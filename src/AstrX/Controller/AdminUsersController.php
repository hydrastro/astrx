<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Admin\AuditLogger;
use AstrX\Auth\Gate;
use function AstrX\Support\configDir;
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
use AstrX\User\DeletionMode;
use AstrX\User\UserRepository;
use AstrX\User\UserResource;
use AstrX\User\UserService;
use AstrX\User\UserSession;

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
        private readonly UserService           $userService,
        private readonly UserSession           $session,
        private readonly AuditLogger           $audit,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        $canManage = $this->gate->can(Permission::ADMIN_USERS);
        $canConfig = $this->gate->can(Permission::ADMIN_CONFIG_USERS);

        if (!$canManage && !$canConfig) {
            http_response_code(403);
            $this->ctx->set('admin_forbidden', true);
            $this->ctx->set('forbidden_message', $this->t->t('admin.forbidden'));
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
        $csrfResult = $this->csrf->verify(self::FORM, self::mStr($posted, '_csrf', ''));
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);
            return;
        }

        $section = self::mStr($posted, 'section', 'manage');

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
            if ($r->isOk()) {
                $this->flash->set('success', $this->t->t('admin.config.saved'));
                $this->audit->log('config.save', $section === 'identicon' ? 'Identicon.config.php' : 'User.config.php')->drainTo($this->collector);
            }
        }
    }

    // ── User management ───────────────────────────────────────────────────────

    /** @param array<string, mixed> $posted */
    private function processManagement(array $posted): void
    {
        $action = self::mStr($posted, 'action', '');
        $hexId  = self::mStr($posted, 'user_id', '');
        if ($hexId === '') {
            $this->flash->set('error', $this->t->t('admin.users.invalid_id'));
            return;
        }

        // Fix 1.1: explicit user-not-found handling.
        $targetResult = $this->userRepo->findById($hexId);
        if (!$targetResult->isOk()) {
            $targetResult->drainTo($this->collector);
            $this->flash->set('error', $this->t->t('admin.users.lookup_failed'));
            return;
        }
        $targetData = $targetResult->unwrap();
        if (!is_array($targetData)) {
            $this->flash->set('error', $this->t->t('admin.users.not_found'));
            return;
        }
        // A TYPED resource, not `(object) $targetData`. The \stdClass a cast
        // produces is registered to CommentPolicy, which has no arm for any
        // user.* permission, so USER_EDIT_ANY / USER_DELETE_ANY / USER_PROMOTE
        // were all decided by the role grant alone and UserPolicy never ran.
        $target = UserResource::fromRow($targetData);
        if ($this->gate->cannot(Permission::USER_EDIT_ANY, $target)) {
            $this->flash->set('error', $this->t->t('admin.users.permission_denied'));
            return;
        }

        switch ($action) {
            case 'update':
                // Fix 1.3: password hashing delegated to UserService.
                $rawPassword = trim(self::mStr($posted, 'password', ''));
                $hashIt      = self::mBool($posted, 'hash_password');

                // FIX H6: role (`type`) privilege-escalation guard. USER_EDIT_ANY
                // (checked above) is NOT enough to change a user's group. A role
                // change is only allowed when the acting user holds USER_PROMOTE,
                // the new group value is <= the acting user's own group value, and
                // it is not a self-promotion to a higher group. Without this, any
                // editor could raise `type` straight from POST.
                $postedType  = self::mInt($posted, 'type', 0);
                $currentType = $target->type->value;
                if ($postedType !== $currentType) {
                    // Compare by PRIVILEGE RANK, not the raw enum value: UserGroup
                    // values are not privilege-ordered (USER=0, ADMIN=1, MOD=2,
                    // GUEST=3), so a numeric `<=` would let a MOD mint an ADMIN.
                    $newGroup    = UserGroup::tryFrom($postedType);
                    $actingRank  = $this->session->userType()->rank();
                    $currentRank = $target->type->rank();
                    $isSelf      = strtolower($hexId) === strtolower($this->session->userId());
                    $promotionAllowed =
                        $newGroup !== null                                    // must be a real group
                        && $this->gate->can(Permission::USER_PROMOTE, $target) // must hold USER_PROMOTE
                        && $newGroup->rank() <= $actingRank                    // never above the actor's own rank
                        && !($isSelf && $newGroup->rank() > $currentRank);     // no self-promotion to a higher rank
                    if (!$promotionAllowed) {
                        $this->flash->set('error', $this->t->t('admin.users.promote_denied'));
                        return;
                    }
                }

                $r = $this->userRepo->adminUpdate(
                    $hexId,
                    trim(self::mStr($posted, 'username', '')),
                    null, // password handled separately below
                    self::mNullableTrimmed($posted, 'mailbox'),
                    self::mNullableTrimmed($posted, 'email'),
                    self::mNullableTrimmed($posted, 'display_name'),
                    $postedType,
                    self::mNullableTrimmed($posted, 'birth'),
                    self::mInt($posted, 'login_attempts', 0),
                    self::mBool($posted, 'verified'),
                    self::mBool($posted, 'deleted'),
                    self::mNullableTrimmed($posted, 'created_at'),
                    self::mNullableTrimmed($posted, 'last_access'),
                );
                $r->drainTo($this->collector);

                // Apply password change separately if requested.
                if ($r->isOk() && $rawPassword !== '') {
                    $pwResult = $hashIt
                        ? $this->userService->adminSetPassword($hexId, $rawPassword)
                        : $this->userService->adminSetPasswordHash($hexId, $rawPassword);
                    $pwResult->drainTo($this->collector);
                    if (!$pwResult->isOk()) {
                        $this->flash->set('error', $this->t->t('admin.users.password_failed'));
                        return;
                    }
                    $this->audit->log('user.password', "user:{$hexId}")->drainTo($this->collector);
                }
                if ($r->isOk()) {
                    $this->flash->set('success', $this->t->t('admin.users.updated'));
                    $this->audit->log('user.update', "user:{$hexId}")->drainTo($this->collector);
                }
                break;

            case 'delete':
                if ($this->gate->cannot(Permission::USER_DELETE_ANY, $target)) {
                    $this->flash->set('error', $this->t->t('admin.users.permission_denied'));
                    return;
                }
                $modeRaw  = self::mStr($posted, 'deletion_mode', DeletionMode::SOFT_REDACT->value);
                $delMode  = DeletionMode::tryFrom($modeRaw) ?? DeletionMode::SOFT_REDACT;
                $r = $this->userService->delete(
                    hexId:         $hexId,
                    mode:          $delMode,
                    adminBypass:   true,
                );
                $r->drainTo($this->collector);
                if ($r->isOk()) {
                    $this->flash->set('success', $this->t->t('admin.users.deleted'));
                    $this->audit->log('user.delete', "user:{$hexId}")->drainTo($this->collector);
                }
                break;

            case 'force_logout':
                // R13: evict ALL of the target's live sessions immediately by
                // bumping their session epoch — the per-request re-validation then
                // drops each session on its next request. USER_EDIT_ANY (required
                // above) gates this; it does not delete or alter the account.
                $r = $this->userRepo->bumpSessionEpoch($hexId);
                $r->drainTo($this->collector);
                if ($r->isOk()) {
                    $this->flash->set('success', $this->t->t('admin.users.force_logout_done'));
                    $this->audit->log('user.force_logout', "user:{$hexId}")->drainTo($this->collector);
                }
                break;
        }
    }

    // ── Config savers ─────────────────────────────────────────────────────────

    /** @param array<string, mixed> $p
     * @return Result<mixed>
     */
    private function saveUserService(array $p): Result
    {
        // R10 RBAC-F2 (MED): captcha enforcement (login/register/recover), the
        // unverified-login bypass, and the username/password strength regexes are
        // SECURITY policy, not routine user management. admin.config.users (a MOD
        // may hold it) scopes user management; weakening these anti-abuse controls
        // is a system decision. For a non-system actor, PRESERVE the current
        // on-disk values so a MOD's save can neither disable a captcha (which would
        // open login brute-force / registration spam), permit unverified logins,
        // nor relax the password policy. Same fail-safe-preserve pattern the
        // login_lockout_* keys already use, and the site_url gate (R9-02).
        $maySetAuthPolicy = $this->gate->can(Permission::ADMIN_CONFIG_SYSTEM);
        $sections = [
            'UserService' => [
                'token_expiration_time'          => max(60, self::mInt($p, 'token_expiration_time', 21600)),
                'allow_register'                 => self::mBool($p, 'allow_register'),
                'allow_login_non_verified_users' => $maySetAuthPolicy
                    ? self::mBool($p, 'allow_login_non_verified_users')
                    : $this->config->getConfigBool('UserService', 'allow_login_non_verified_users', false),
                'require_email'                  => self::mBool($p, 'require_email'),
                'require_recovery_email'         => self::mBool($p, 'require_recovery_email'),
                'send_verification_email'        => self::mBool($p, 'send_verification_email'),
                'send_password_reset_email'      => self::mBool($p, 'send_password_reset_email'),
                'require_display_name'           => self::mBool($p, 'require_display_name'),
                'require_birth_date'             => self::mBool($p, 'require_birth_date'),
                'case_sensitive_usernames'       => self::mBool($p, 'case_sensitive_usernames'),
                'minimum_age'                    => max(0, self::mInt($p, 'minimum_age', 0)),
                'maximum_age'                    => max(0, self::mInt($p, 'maximum_age', 0)),
                // Fall back to ALWAYS, not ON_X_FAILED: a missing form field or a
                // missing config key must not silently land on the mode whose
                // threshold the attacker controls (their own session counter).
                'login_captcha_type'             => $maySetAuthPolicy
                    ? self::mInt($p, 'login_captcha_type', UserService::CAPTCHA_SHOW_ALWAYS)
                    : $this->config->getConfigInt('UserService', 'login_captcha_type', UserService::CAPTCHA_SHOW_ALWAYS),
                'login_captcha_attempts'         => max(1, self::mInt($p, 'login_captcha_attempts', 3)),
                // Brute-force lockout (fix M4) has no form field yet; preserve the
                // current on-disk values so saving this page never silently drops them.
                'login_lockout_threshold'        => max(0, $this->config->getConfigInt('UserService', 'login_lockout_threshold', 10)),
                'login_lockout_cooldown'         => max(1, $this->config->getConfigInt('UserService', 'login_lockout_cooldown', 900)),
                'register_captcha_type'          => $maySetAuthPolicy
                    ? self::mInt($p, 'register_captcha_type', UserService::CAPTCHA_SHOW_ALWAYS)
                    : $this->config->getConfigInt('UserService', 'register_captcha_type', UserService::CAPTCHA_SHOW_ALWAYS),
                'recover_captcha_type'           => $maySetAuthPolicy
                    ? self::mInt($p, 'recover_captcha_type', UserService::CAPTCHA_SHOW_ALWAYS)
                    : $this->config->getConfigInt('UserService', 'recover_captcha_type', UserService::CAPTCHA_SHOW_ALWAYS),
                'remember_me_time'               => max(0, self::mInt($p, 'remember_me_time', 2592000)),
                'username_regex'                 => $maySetAuthPolicy
                    ? $this->parseRegexTable($p, 'username')
                    : $this->config->getConfigArray('UserService', 'username_regex', []),
                'password_regex'                 => $maySetAuthPolicy
                    ? $this->parseRegexTable($p, 'password')
                    : $this->config->getConfigArray('UserService', 'password_regex', []),
            ],
        ];
        // site_url/site_name (EmailService section) are the base of every emailed
        // auth link (verification/recovery) and the feed/sitemap absolute URLs —
        // SYSTEM-level. Only an actor with ADMIN_CONFIG_SYSTEM may write them from
        // this page, so a MOD (who holds admin.config.users but NOT
        // admin.config.system) can't repoint auth links to a phishing host (R9
        // HIGH-2). Skipping the section for a MOD also means a MOD's users-config
        // save never blanks site_url.
        if ($this->gate->can(Permission::ADMIN_CONFIG_SYSTEM)) {
            $sections['EmailService'] = [
                'site_url'  => self::mStr($p, 'site_url',  ''),
                'site_name' => self::mStr($p, 'site_name', ''),
            ];
        }
        return $this->writer->write('User', array_merge($this->loadUserConfig(), $sections));
    }

    /** @param array<string, mixed> $p
     * @return Result<mixed>
     */
    private function saveAvatar(array $p): Result
    {
        return $this->writer->write('User', array_merge(
            $this->loadUserConfig(),
            ['AvatarService' => [
                // R11 (MED): avatar_dir is system-level (repoints where avatars are
                // written/served); a MOD (admin.config.users) must not relocate it,
                // so preserve the on-disk value for a non-system actor.
                'avatar_dir'       => $this->gate->can(Permission::ADMIN_CONFIG_SYSTEM)
                    ? trim(self::mStr($p, 'avatar_dir', ''))
                    : $this->config->getConfigString('AvatarService', 'avatar_dir', ''),
                'avatar_file_size' => max(1024, self::mInt($p, 'avatar_file_size', 1048576)),
                'use_identicons'   => self::mBool($p, 'use_identicons'),
            ]]
        ));
    }

    /** @param array<string, mixed> $p
     * @return Result<mixed>
     */
    private function saveIdenticon(array $p): Result
    {
        return $this->writer->write('Identicon', [
            'IdenticonRenderer' => [
                'size'         => max(16, self::mInt($p, 'size', 256)),
                'tiles'        => max(2,  self::mInt($p, 'tiles', 6)),
                'colors'       => max(1,  self::mInt($p, 'colors', 1)),
                'high_quality' => self::mBool($p, 'high_quality'),
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
            $editId     = (is_scalar($vedit = $this->request->query()->get('edit') ?? '') ? (string)$vedit : '');
            $listResult = $this->userRepo->listAll();
            $listResult->drainTo($this->collector);
            /** @var list<array<string,mixed>> $rawList */
            $rawList  = $listResult->isOk() ? $listResult->unwrap() : [];

            $userList = [];
            foreach ($rawList as $row) {
                $isEditing = ($editId !== '' && $row['id'] === $editId);
                if ($isEditing) {
                    $full = $this->userRepo->adminFindById($editId);
                    $full->drainTo($this->collector);
                    /** @var array<string,mixed> $fd */
                    $fd = ($full->isOk() && $full->unwrap() !== null) ? $full->unwrap() : $row;
                    $typeV = $fd['type'] ?? 0;
                    $fd['type_options'] = $this->buildTypeOptions(is_int($typeV) ? $typeV : (is_numeric($typeV) ? (int)$typeV : 0));
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
            $loginType    = $this->config->getConfigInt('UserService', 'login_captcha_type',    UserService::CAPTCHA_SHOW_ON_X_FAILED);
            $registerType = $this->config->getConfigInt('UserService', 'register_captcha_type', UserService::CAPTCHA_SHOW_ALWAYS);
            $recoverType  = $this->config->getConfigInt('UserService', 'recover_captcha_type',  UserService::CAPTCHA_SHOW_ALWAYS);
            /** @param array<string,mixed>[] $o */
            $sel = function (mixed $v, array $o): array {
                return array_map(function (mixed $x) use ($v): mixed {
                    if (!is_array($x)) { return $x; }
                    /** @var array<string,mixed> $x */
                    return array_merge($x, ['selected' => $x['value'] === $v]);
                }, $o);
            };

            $this->ctx->set('cfg_token_expiration_time',          $this->config->getConfigInt('UserService', 'token_expiration_time',          21600));
            $this->ctx->set('cfg_allow_register',                 $this->config->getConfigBool('UserService', 'allow_register',                 true));
            $this->ctx->set('cfg_allow_login_non_verified_users', $this->config->getConfigBool('UserService', 'allow_login_non_verified_users', true));
            $this->ctx->set('cfg_require_email',                  $this->config->getConfigBool('UserService', 'require_email',                  true));
            $this->ctx->set('cfg_require_recovery_email',         $this->config->getConfigBool('UserService', 'require_recovery_email',         true));
            // Email transactional flags (fix115)
            $this->ctx->set('cfg_send_verification_email',        $this->config->getConfigBool('UserService', 'send_verification_email',        true));
            $this->ctx->set('cfg_send_password_reset_email',      $this->config->getConfigBool('UserService', 'send_password_reset_email',      true));
            $this->ctx->set('cfg_site_url',                       $this->config->getConfigString('EmailService','site_url',                     ''));
            $this->ctx->set('cfg_site_name',                      $this->config->getConfigString('EmailService','site_name',                    'AstrX'));
            $this->ctx->set('cfg_require_display_name',           $this->config->getConfigBool('UserService', 'require_display_name',           true));
            $this->ctx->set('cfg_require_birth_date',             $this->config->getConfigBool('UserService', 'require_birth_date',             false));
            $this->ctx->set('cfg_case_sensitive_usernames',       $this->config->getConfigBool('UserService', 'case_sensitive_usernames',       false));
            $this->ctx->set('cfg_minimum_age',                    $this->config->getConfigInt('UserService', 'minimum_age',                    0));
            $this->ctx->set('cfg_maximum_age',                    $this->config->getConfigInt('UserService', 'maximum_age',                    0));
            $this->ctx->set('cfg_login_captcha_attempts',         $this->config->getConfigInt('UserService', 'login_captcha_attempts',         3));
            $this->ctx->set('cfg_remember_me_time',               $this->config->getConfigInt('UserService', 'remember_me_time',               2592000));
            $this->ctx->set('login_captcha_options',    $sel($loginType,    $captchaOpts));
            $this->ctx->set('register_captcha_options', $sel($registerType, $captchaOpts));
            $this->ctx->set('recover_captcha_options',  $sel($recoverType,  $captchaOpts));

            $usernameRulesRaw = $this->config->getConfigArray('UserService', 'username_regex');
            $passwordRulesRaw = $this->config->getConfigArray('UserService', 'password_regex');
            /** @phpstan-var array<int,array<string,mixed>> $usernameRulesTyped */
            $usernameRulesTyped = $usernameRulesRaw;
            /** @phpstan-var array<int,array<string,mixed>> $passwordRulesTyped */
            $passwordRulesTyped = $passwordRulesRaw;
            $this->ctx->set('username_regex_list', $this->flattenRegex($usernameRulesTyped));
            $this->ctx->set('password_regex_list', $this->flattenRegex($passwordRulesTyped));
            $this->ctx->set('has_username_regex',  $usernameRulesRaw !== []);
            $this->ctx->set('has_password_regex',  $passwordRulesRaw !== []);

            $this->ctx->set('cfg_avatar_dir',            $this->config->getConfigString('AvatarService',      'avatar_dir',       ''));
            $this->ctx->set('cfg_avatar_file_size',      $this->config->getConfigInt('AvatarService',      'avatar_file_size',  1048576));
            $this->ctx->set('cfg_use_identicons',        $this->config->getConfigBool('AvatarService',      'use_identicons',    true));
            $this->ctx->set('cfg_identicon_size',        $this->config->getConfigInt('IdenticonRenderer',  'size',              256));
            $this->ctx->set('cfg_identicon_tiles',       $this->config->getConfigInt('IdenticonRenderer',  'tiles',             6));
            $this->ctx->set('cfg_identicon_colors',      $this->config->getConfigInt('IdenticonRenderer',  'colors',            1));
            $this->ctx->set('cfg_identicon_high_quality',$this->config->getConfigBool('IdenticonRenderer',  'high_quality',      true));
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
            $k = is_int($key) ? $key : (is_numeric($key) ? (int)$key : 0);
            if ($k <= 0) { continue; }
            $patRaw = $patterns[$i] ?? '';
            $pattern = trim(is_scalar($patRaw) ? (string)$patRaw : '');
            // Reject an uncompilable pattern at save (R9): storing a malformed
            // regex would 500 every subsequent registration / password change when
            // checkRegex runs it. Skip it here so it never reaches disk.
            if ($pattern === '' || @preg_match($pattern, '') === false) { continue; }
            $msgRaw = $messages[$i] ?? '';
            $result[$k] = [
                'regex'        => $pattern,
                'enabled'      => isset($enabled[$i]),
                'checking_for' => isset($checkingFor[$i]),
                'message'      => trim(is_scalar($msgRaw) ? (string)$msgRaw : ''),
            ];
        }
        return $result;
    }

    /** @return array<string, array<string, mixed>> */
    private function loadUserConfig(): array
    {
        $path = (configDir() . 'User.config.php');
        if (!is_file($path)) { return []; }
        $loaded = @include $path;
        if (!is_array($loaded)) { return []; }
        /** @var array<string,array<string,mixed>> $loaded */
        return $loaded;
    }

    /**
     * @param array<int,array<string,mixed>> $rules
     * @return list<array<string,mixed>>
     */
    private function flattenRegex(array $rules): array
    {
        $list = [];
        foreach ($rules as $key => $rule) {
            $list[] = [
                'key'          => $key,
                'regex'        => self::mStr($rule, 'regex', ''),
                'enabled'      => (bool)   ($rule['enabled']      ?? true),
                'checking_for' => self::mBool($rule, 'checking_for'),
                'message'      => self::mStr($rule, 'message', ''),
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
            $this->ctx->set('btn_force_logout',     $this->t->t('admin.users.force_logout'));
            $this->ctx->set('force_logout_help',    $this->t->t('admin.users.force_logout_help'));
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
            // Email transactional flags (fix115)
            $this->ctx->set('label_send_verification_email',       $this->t->t('admin.config.field.send_verification_email'));
            $this->ctx->set('label_send_password_reset_email',     $this->t->t('admin.config.field.send_password_reset_email'));
            $this->ctx->set('label_site_url',                      $this->t->t('admin.config.field.site_url'));
            $this->ctx->set('label_site_name',                     $this->t->t('admin.config.field.site_name'));
            $this->ctx->set('section_email_settings',              $this->t->t('admin.config.users.email_settings'));
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
