<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Config\Config;
use AstrX\Config\ConfigWriter;
use AstrX\Csrf\CsrfHandler;
use AstrX\Http\Request;
use AstrX\Http\Response;
use AstrX\I18n\Translator;
use AstrX\Page\Page;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\Session\FlashBag;
use AstrX\Session\PrgHandler;
use AstrX\Template\DefaultTemplateContext;
use AstrX\User\UserGroup;
use AstrX\User\UserRepository;
use AstrX\User\UserService;

/**
 * Admin users — user account management + user service configuration on one page.
 *
 * Moderation section (requires ADMIN_USERS):
 *   List all users. Inline edit: all fields, password hash, role, flags.
 *   Soft delete.
 *
 * Config section (requires ADMIN_CONFIG_USERS):
 *   UserService settings (registration, required fields, age limits,
 *   captcha policy, remember-me, username/password validation regexes).
 *   AvatarService settings (directory, max size, identicons toggle).
 *   IdenticonRenderer settings (size, tiles, colors, high_quality).
 *   Writes User.config.php and Identicon.config.php atomically.
 *
 * Each section only renders when the current user has the required permission.
 */
final class AdminUsersController extends AbstractController
{
    private const FORM = 'admin_users';

    public function __construct(
        DiagnosticsCollector                   $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request               $request,
        private readonly UserRepository        $userRepo,
        private readonly Config                $config,
        private readonly ConfigWriter          $writer,
        private readonly Gate                  $gate,
        private readonly CsrfHandler           $csrf,
        private readonly PrgHandler            $prg,
        private readonly FlashBag              $flash,
        private readonly Page                  $page,
        private readonly UrlGenerator          $urlGen,
        private readonly Translator            $t,
    ) {
        parent::__construct($collector);
    }

    public function handle(): Result
    {
        $canManage = $this->gate->can(Permission::ADMIN_USERS);
        $canConfig = $this->gate->can(Permission::ADMIN_CONFIG_USERS);

        if (!$canManage && !$canConfig) {
            http_response_code(403);
            return $this->ok();
        }

        $resolvedUrlId = $this->page->i18n
            ? $this->t->t($this->page->urlId, fallback: $this->page->urlId)
            : $this->page->urlId;
        $selfUrl = $this->urlGen->toPage($resolvedUrlId);

        $prgToken = $this->request->query()->get($this->prg->tokenQueryKey());
        if (is_string($prgToken) && $prgToken !== '') {
            $this->processForm($prgToken, $selfUrl, $canManage, $canConfig);
            Response::redirect($selfUrl)->send()->drainTo($this->collector);
            exit;
        }

        $this->buildContext($selfUrl, $canManage, $canConfig);
        return $this->ok();
    }

    // =========================================================================

    private function processForm(
        string $prgToken,
        string $selfUrl,
        bool   $canManage,
        bool   $canConfig,
    ): void {
        $posted     = $this->prg->pull($prgToken) ?? [];
        $csrfResult = $this->csrf->verify(self::FORM, (string) ($posted['_csrf'] ?? ''));
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);
            return;
        }

        $section = (string) ($posted['section'] ?? 'manage');

        if ($section === 'manage' && $canManage) {
            $this->processManagement($posted);
            return;
        }

        if (in_array($section, ['userservice', 'avatar', 'identicon'], true) && $canConfig) {
            $r = match ($section) {
                'userservice' => $this->saveUserService($posted),
                'avatar'      => $this->saveAvatar($posted),
                'identicon'   => $this->saveIdenticon($posted),
            };
            $r->drainTo($this->collector);
            if ($r->isOk()) { $this->flash->set('success', $this->t->t('admin.config.saved')); }
        }
    }

    // ── User management ───────────────────────────────────────────────────────

    /** @param array<string, mixed> $posted */
    private function processManagement(array $posted): void
    {
        $action = (string) ($posted['action']  ?? '');
        $hexId  = (string) ($posted['user_id'] ?? '');
        if ($hexId === '') { return; }

        $targetResult = $this->userRepo->findById($hexId);
        if ($targetResult->isOk() && $targetResult->unwrap() !== null) {
            $target = (object) $targetResult->unwrap();
            if ($this->gate->cannot(Permission::USER_EDIT_ANY, $target)) {
                $this->flash->set('error', $this->t->t('admin.users.permission_denied'));
                return;
            }
        }

        switch ($action) {
            case 'update':
                $rawPassword = trim((string) ($posted['password'] ?? ''));
                $hashIt      = !empty($posted['hash_password']);
                $password    = $rawPassword !== ''
                    ? ($hashIt ? password_hash($rawPassword, PASSWORD_ARGON2ID) : $rawPassword)
                    : null;
                $r = $this->userRepo->adminUpdate(
                    $hexId,
                    trim((string) ($posted['username']      ?? '')),
                    $password,
                    ($posted['mailbox']      ?? '') !== '' ? trim((string) $posted['mailbox'])      : null,
                    ($posted['email']        ?? '') !== '' ? trim((string) $posted['email'])        : null,
                    ($posted['display_name'] ?? '') !== '' ? trim((string) $posted['display_name']) : null,
                    (int) ($posted['type']           ?? 0),
                    ($posted['birth']        ?? '') !== '' ? trim((string) $posted['birth'])        : null,
                    (int) ($posted['login_attempts'] ?? 0),
                    !empty($posted['verified']),
                    !empty($posted['deleted']),
                    ($posted['created_at']  ?? '') !== '' ? trim((string) $posted['created_at'])  : null,
                    ($posted['last_access'] ?? '') !== '' ? trim((string) $posted['last_access']) : null,
                );
                $r->drainTo($this->collector);
                if ($r->isOk()) { $this->flash->set('success', $this->t->t('admin.users.updated')); }
                break;

            case 'delete':
                $r = $this->userRepo->softDelete($hexId);
                $r->drainTo($this->collector);
                if ($r->isOk()) { $this->flash->set('success', $this->t->t('admin.users.deleted')); }
                break;
        }
    }

    // ── Config savers ─────────────────────────────────────────────────────────

    /** @param array<string, mixed> $p */
    private function saveUserService(array $p): Result
    {
        return $this->writer->write('User', array_merge(
            $this->loadUserConfig(),
            ['UserService' => [
                'token_expiration_time'          => max(60, (int) ($p['token_expiration_time'] ?? 21600)),
                'allow_register'                 => !empty($p['allow_register']),
                'allow_login_non_verified_users' => !empty($p['allow_login_non_verified_users']),
                'require_email'                  => !empty($p['require_email']),
                'require_recovery_email'         => !empty($p['require_recovery_email']),
                'require_display_name'           => !empty($p['require_display_name']),
                'require_birth_date'             => !empty($p['require_birth_date']),
                'case_sensitive_usernames'       => !empty($p['case_sensitive_usernames']),
                'minimum_age'                    => max(0, (int) ($p['minimum_age'] ?? 0)),
                'maximum_age'                    => max(0, (int) ($p['maximum_age'] ?? 0)),
                'login_captcha_type'             => (int) ($p['login_captcha_type']    ?? UserService::CAPTCHA_SHOW_ON_X_FAILED),
                'login_captcha_attempts'         => max(1, (int) ($p['login_captcha_attempts'] ?? 3)),
                'register_captcha_type'          => (int) ($p['register_captcha_type'] ?? UserService::CAPTCHA_SHOW_ALWAYS),
                'recover_captcha_type'           => (int) ($p['recover_captcha_type']  ?? UserService::CAPTCHA_SHOW_ALWAYS),
                'remember_me_time'               => max(0, (int) ($p['remember_me_time'] ?? 2592000)),
                'username_regex'                 => $this->parseRegexTable($p, 'username'),
                'password_regex'                 => $this->parseRegexTable($p, 'password'),
            ]]
        ));
    }

    /** @param array<string, mixed> $p */
    private function saveAvatar(array $p): Result
    {
        return $this->writer->write('User', array_merge(
            $this->loadUserConfig(),
            ['AvatarService' => [
                'avatar_dir'       => trim((string) ($p['avatar_dir']       ?? '')),
                'avatar_file_size' => max(1024, (int) ($p['avatar_file_size'] ?? 1048576)),
                'use_identicons'   => !empty($p['use_identicons']),
            ]]
        ));
    }

    /** @param array<string, mixed> $p */
    private function saveIdenticon(array $p): Result
    {
        return $this->writer->write('Identicon', [
            'IdenticonRenderer' => [
                'size'         => max(16, (int) ($p['size']   ?? 256)),
                'tiles'        => max(2,  (int) ($p['tiles']  ?? 6)),
                'colors'       => max(1,  (int) ($p['colors'] ?? 1)),
                'high_quality' => !empty($p['high_quality']),
            ],
        ]);
    }

    // ── Context builder ───────────────────────────────────────────────────────

    private function buildContext(string $selfUrl, bool $canManage, bool $canConfig): void
    {
        $csrfToken = $this->csrf->generate(self::FORM);
        $prgId     = $this->prg->createId($selfUrl);

        $this->ctx->set('can_manage',  $canManage);
        $this->ctx->set('can_config',  $canConfig);
        $this->ctx->set('base_url',    $selfUrl);
        $this->ctx->set('csrf_token',  $csrfToken);
        $this->ctx->set('prg_id',      $prgId);

        if ($canManage) {
            $editId     = (string) ($this->request->query()->get('edit') ?? '');
            $listResult = $this->userRepo->listAll();
            $listResult->drainTo($this->collector);
            $rawList  = $listResult->isOk() ? $listResult->unwrap() : [];

            $userList = [];
            foreach ($rawList as $row) {
                $isEditing = ($editId !== '' && $row['id'] === $editId);
                if ($isEditing) {
                    $full = $this->userRepo->adminFindById($editId);
                    $full->drainTo($this->collector);
                    $fd = ($full->isOk() && $full->unwrap() !== null) ? $full->unwrap() : $row;
                    $fd['type_options'] = $this->buildTypeOptions((int) $fd['type']);
                    $row['editing'] = [$fd];
                } else {
                    $row['editing'] = false;
                }
                $userList[] = $row;
            }
            $this->ctx->set('user_list', $userList);
        }

        if ($canConfig) {
            $captchaOpts = [
                ['value' => UserService::CAPTCHA_SHOW_ALWAYS,      'label' => $this->t->t('admin.config.users.captcha_always')],
                ['value' => UserService::CAPTCHA_SHOW_NEVER,       'label' => $this->t->t('admin.config.users.captcha_never')],
                ['value' => UserService::CAPTCHA_SHOW_ON_X_FAILED, 'label' => $this->t->t('admin.config.users.captcha_on_fail')],
            ];
            $loginType    = (int) $this->config->getConfig('UserService', 'login_captcha_type',    UserService::CAPTCHA_SHOW_ON_X_FAILED);
            $registerType = (int) $this->config->getConfig('UserService', 'register_captcha_type', UserService::CAPTCHA_SHOW_ALWAYS);
            $recoverType  = (int) $this->config->getConfig('UserService', 'recover_captcha_type',  UserService::CAPTCHA_SHOW_ALWAYS);
            $sel          = fn($v, $o) => array_map(fn($x) => array_merge($x, ['selected' => $x['value'] === $v]), $o);

            $this->ctx->set('cfg_token_expiration_time',          (int)  $this->config->getConfig('UserService', 'token_expiration_time',          21600));
            $this->ctx->set('cfg_allow_register',                 (bool) $this->config->getConfig('UserService', 'allow_register',                 true));
            $this->ctx->set('cfg_allow_login_non_verified_users', (bool) $this->config->getConfig('UserService', 'allow_login_non_verified_users', true));
            $this->ctx->set('cfg_require_email',                  (bool) $this->config->getConfig('UserService', 'require_email',                  true));
            $this->ctx->set('cfg_require_recovery_email',         (bool) $this->config->getConfig('UserService', 'require_recovery_email',         true));
            $this->ctx->set('cfg_require_display_name',           (bool) $this->config->getConfig('UserService', 'require_display_name',           true));
            $this->ctx->set('cfg_require_birth_date',             (bool) $this->config->getConfig('UserService', 'require_birth_date',             false));
            $this->ctx->set('cfg_case_sensitive_usernames',       (bool) $this->config->getConfig('UserService', 'case_sensitive_usernames',       false));
            $this->ctx->set('cfg_minimum_age',                    (int)  $this->config->getConfig('UserService', 'minimum_age',                    0));
            $this->ctx->set('cfg_maximum_age',                    (int)  $this->config->getConfig('UserService', 'maximum_age',                    0));
            $this->ctx->set('cfg_login_captcha_attempts',         (int)  $this->config->getConfig('UserService', 'login_captcha_attempts',         3));
            $this->ctx->set('cfg_remember_me_time',               (int)  $this->config->getConfig('UserService', 'remember_me_time',               2592000));
            $this->ctx->set('login_captcha_options',    $sel($loginType,    $captchaOpts));
            $this->ctx->set('register_captcha_options', $sel($registerType, $captchaOpts));
            $this->ctx->set('recover_captcha_options',  $sel($recoverType,  $captchaOpts));

            $usernameRules = (array) $this->config->getConfig('UserService', 'username_regex', []);
            $passwordRules = (array) $this->config->getConfig('UserService', 'password_regex', []);
            $this->ctx->set('username_regex_list', $this->flattenRegex($usernameRules));
            $this->ctx->set('password_regex_list', $this->flattenRegex($passwordRules));
            $this->ctx->set('has_username_regex',  $usernameRules !== []);
            $this->ctx->set('has_password_regex',  $passwordRules !== []);

            $this->ctx->set('cfg_avatar_dir',            (string) $this->config->getConfig('AvatarService',      'avatar_dir',       ''));
            $this->ctx->set('cfg_avatar_file_size',      (int)    $this->config->getConfig('AvatarService',      'avatar_file_size',  1048576));
            $this->ctx->set('cfg_use_identicons',        (bool)   $this->config->getConfig('AvatarService',      'use_identicons',    true));
            $this->ctx->set('cfg_identicon_size',        (int)    $this->config->getConfig('IdenticonRenderer',  'size',              256));
            $this->ctx->set('cfg_identicon_tiles',       (int)    $this->config->getConfig('IdenticonRenderer',  'tiles',             6));
            $this->ctx->set('cfg_identicon_colors',      (int)    $this->config->getConfig('IdenticonRenderer',  'colors',            1));
            $this->ctx->set('cfg_identicon_high_quality',(bool)   $this->config->getConfig('IdenticonRenderer',  'high_quality',      true));
        }

        $this->setI18n($canManage, $canConfig);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return list<array{value:int,name:string,selected:bool}> */
    private function buildTypeOptions(int $current): array
    {
        return array_map(
            fn(UserGroup $g) => ['value' => $g->value, 'name' => $g->name, 'selected' => $g->value === $current],
            UserGroup::cases()
        );
    }

    /**
     * @param array<string, mixed> $p
     * @return array<int, array{regex:string,enabled:bool,checking_for:bool,message:string}>
     */
    private function parseRegexTable(array $p, string $prefix): array
    {
        $keys        = (array) ($p[$prefix . '_regex_key']          ?? []);
        $patterns    = (array) ($p[$prefix . '_regex_pattern']      ?? []);
        $checkingFor = (array) ($p[$prefix . '_regex_checking_for'] ?? []);
        $enabled     = (array) ($p[$prefix . '_regex_enabled']      ?? []);
        $messages    = (array) ($p[$prefix . '_regex_message']      ?? []);

        $result = [];
        foreach ($keys as $i => $key) {
            $k = (int) $key;
            if ($k <= 0) { continue; }
            $pattern = trim((string) ($patterns[$i] ?? ''));
            if ($pattern === '') { continue; }
            $result[$k] = [
                'regex'        => $pattern,
                'enabled'      => isset($enabled[$i]),
                'checking_for' => isset($checkingFor[$i]),
                'message'      => trim((string) ($messages[$i] ?? '')),
            ];
        }
        return $result;
    }

    /** @return array<string, array<string, mixed>> */
    private function loadUserConfig(): array
    {
        $path = (defined('CONFIG_DIR') ? CONFIG_DIR : '') . 'User.config.php';
        if (!is_file($path)) { return []; }
        $loaded = @include $path;
        return is_array($loaded) ? $loaded : [];
    }

    /** @param array<int, array<string,mixed>> $rules @return list<array<string,mixed>> */
    private function flattenRegex(array $rules): array
    {
        $list = [];
        foreach ($rules as $key => $rule) {
            $list[] = [
                'key'          => $key,
                'regex'        => (string) ($rule['regex']        ?? ''),
                'enabled'      => (bool)   ($rule['enabled']      ?? true),
                'checking_for' => (bool)   ($rule['checking_for'] ?? false),
                'message'      => (string) ($rule['message']      ?? ''),
            ];
        }
        return $list;
    }

    private function setI18n(bool $canManage, bool $canConfig): void
    {
        $this->ctx->set('admin_users_heading', $this->t->t('admin.nav.users'));

        if ($canManage) {
            $this->ctx->set('label_id',             $this->t->t('admin.field.id'));
            $this->ctx->set('label_username',       $this->t->t('admin.field.username'));
            $this->ctx->set('label_display_name',   $this->t->t('admin.users.display_name'));
            $this->ctx->set('label_mailbox',        $this->t->t('admin.users.mailbox'));
            $this->ctx->set('label_email',          $this->t->t('admin.users.email'));
            $this->ctx->set('label_password',       $this->t->t('admin.users.password'));
            $this->ctx->set('label_hash_password',  $this->t->t('admin.users.hash_password'));
            $this->ctx->set('label_password_hint',  $this->t->t('admin.users.password_hint'));
            $this->ctx->set('label_birth',          $this->t->t('admin.users.birth'));
            $this->ctx->set('label_type',           $this->t->t('admin.field.type'));
            $this->ctx->set('label_verified',       $this->t->t('admin.field.verified'));
            $this->ctx->set('label_deleted',        $this->t->t('admin.field.deleted'));
            $this->ctx->set('label_login_attempts', $this->t->t('admin.users.login_attempts'));
            $this->ctx->set('label_last_access',    $this->t->t('admin.users.last_access'));
            $this->ctx->set('label_created_at',     $this->t->t('admin.field.date'));
            $this->ctx->set('label_token_hash',     $this->t->t('admin.users.token_hash'));
            $this->ctx->set('label_token_type',     $this->t->t('admin.users.token_type'));
            $this->ctx->set('label_token_used',     $this->t->t('admin.users.token_used'));
            $this->ctx->set('label_token_expires',  $this->t->t('admin.users.token_expires'));
            $this->ctx->set('label_actions',        $this->t->t('admin.field.actions'));
            $this->ctx->set('btn_update',           $this->t->t('admin.btn.update'));
            $this->ctx->set('btn_delete',           $this->t->t('admin.btn.delete'));
            $this->ctx->set('btn_edit',             $this->t->t('admin.btn.edit'));
            $this->ctx->set('btn_cancel',           $this->t->t('admin.btn.cancel'));
        }

        if ($canConfig) {
            $this->ctx->set('section_config_heading',              $this->t->t('admin.config.users.heading'));
            $this->ctx->set('section_userservice',                 $this->t->t('admin.config.users.userservice'));
            $this->ctx->set('section_avatar',                      $this->t->t('admin.config.users.avatar'));
            $this->ctx->set('section_identicon',                   $this->t->t('admin.config.users.identicon'));
            $this->ctx->set('label_token_expiration_time',         $this->t->t('admin.config.field.token_expiration_time'));
            $this->ctx->set('label_allow_register',                $this->t->t('admin.config.field.allow_register'));
            $this->ctx->set('label_allow_login_non_verified_users',$this->t->t('admin.config.field.allow_login_non_verified_users'));
            $this->ctx->set('label_require_email',                 $this->t->t('admin.config.field.require_email_user'));
            $this->ctx->set('label_require_recovery_email',        $this->t->t('admin.config.field.require_recovery_email'));
            $this->ctx->set('label_require_display_name',          $this->t->t('admin.config.field.require_display_name'));
            $this->ctx->set('label_require_birth_date',            $this->t->t('admin.config.field.require_birth_date'));
            $this->ctx->set('label_case_sensitive_usernames',      $this->t->t('admin.config.field.case_sensitive_usernames'));
            $this->ctx->set('label_minimum_age',                   $this->t->t('admin.config.field.minimum_age'));
            $this->ctx->set('label_maximum_age',                   $this->t->t('admin.config.field.maximum_age'));
            $this->ctx->set('label_login_captcha_type',            $this->t->t('admin.config.field.login_captcha_type'));
            $this->ctx->set('label_login_captcha_attempts',        $this->t->t('admin.config.field.login_captcha_attempts'));
            $this->ctx->set('label_register_captcha_type',         $this->t->t('admin.config.field.register_captcha_type'));
            $this->ctx->set('label_recover_captcha_type',          $this->t->t('admin.config.field.recover_captcha_type'));
            $this->ctx->set('label_remember_me_time',              $this->t->t('admin.config.field.remember_me_time'));
            $this->ctx->set('label_username_regex',                $this->t->t('admin.config.field.username_regex'));
            $this->ctx->set('label_password_regex',                $this->t->t('admin.config.field.password_regex'));
            $this->ctx->set('label_regex_key',                     $this->t->t('admin.config.field.regex_key'));
            $this->ctx->set('label_regex_pattern',                 $this->t->t('admin.config.field.regex_pattern'));
            $this->ctx->set('label_regex_checking_for',            $this->t->t('admin.config.field.regex_checking_for'));
            $this->ctx->set('label_regex_enabled',                 $this->t->t('admin.config.field.regex_enabled'));
            $this->ctx->set('label_regex_message',                 $this->t->t('admin.config.field.regex_message'));
            $this->ctx->set('label_avatar_dir',                    $this->t->t('admin.config.field.avatar_dir'));
            $this->ctx->set('label_avatar_file_size',              $this->t->t('admin.config.field.avatar_file_size'));
            $this->ctx->set('label_use_identicons',                $this->t->t('admin.config.field.use_identicons'));
            $this->ctx->set('label_identicon_size',                $this->t->t('admin.config.field.identicon_size'));
            $this->ctx->set('label_identicon_tiles',               $this->t->t('admin.config.field.identicon_tiles'));
            $this->ctx->set('label_identicon_colors',              $this->t->t('admin.config.field.identicon_colors'));
            $this->ctx->set('label_identicon_high_quality',        $this->t->t('admin.config.field.identicon_high_quality'));
            $this->ctx->set('btn_save',                            $this->t->t('admin.btn.save'));
            $this->ctx->set('btn_add_regex',                       $this->t->t('admin.config.comments.add_regex'));
            $this->ctx->set('btn_delete',                          $this->t->t('admin.btn.delete'));
        }
    }
}