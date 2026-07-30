<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Csrf\CsrfHandler;
use AstrX\Http\Request;
use AstrX\Http\Response;
use AstrX\Mail\EmailService;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\Session\PrgHandler;
use AstrX\Template\DefaultTemplateContext;
use AstrX\User\AvatarService;
use AstrX\User\DeletionMode;
use AstrX\Api\ApiKeyService;
use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Theme\ThemeService;
use AstrX\User\UserService;
use AstrX\User\UserSession;

/**
 * User settings page.
 *
 * All settings forms POST via PRG. Each form carries:
 *   prg_id    — PRG target ID for this page
 *   _csrf     — CSRF token scoped to this action
 *   action    — which setting to change (change_username, change_password, etc.)
 *   ... action-specific fields
 *
 * CSRF tokens are scoped per action (e.g. 'settings_change_password') so
 * multiple forms on the same page don't interfere.
 */
final class UserSettingsController extends AbstractController
{
    public function __construct(
        DiagnosticsCollector                   $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request               $request,
        private readonly UserSession           $session,
        private readonly UserService           $userService,
        private readonly AvatarService         $avatarService,
        private readonly CsrfHandler           $csrf,
        private readonly PrgHandler            $prg,
        private readonly UrlGenerator          $urlGen,
        private readonly Translator            $t,
        private readonly ThemeService          $themeService,
        private readonly ApiKeyService         $apiKeys,
        private readonly Gate                  $gate,
        private readonly EmailService          $emailService,
    ) {
        parent::__construct($collector);
    }

    /**
     * Generate a verification token and email a link to $toAddress, mirroring the
     * registration flow. Best-effort: gated on the send-verification config, and
     * any failure is drained as a diagnostic rather than blocking the action.
     */
    private function sendVerificationTo(string $hexId, string $toAddress, int $tokenType): void
    {
        if (!$this->userService->sendVerificationEmail()) {
            return;
        }
        $tokenResult = $this->userService->generateToken($hexId, $tokenType);
        $tokenResult->drainTo($this->collector);
        if (!$tokenResult->isOk()) {
            return;
        }
        /** @var array{token:string,user_id:string,expires_at:int} $tok */
        $tok  = $tokenResult->unwrap();
        $name = $this->session->displayName() !== ''
            ? $this->session->displayName()
            : $this->session->username();
        $this->emailService->sendVerificationEmail(
            toAddress: $toAddress,
            toName:    $name,
            username:  $this->session->username(),
            token:     $tok['token'],
            userHexId: $hexId,
        )->drainTo($this->collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        if (!$this->session->isLoggedIn()) {
            Response::redirect($this->urlGen->toPage($this->t->t('WORDING_LOGIN')))
                ->send()->drainTo($this->collector);
            exit;
        }

        $prgToken = $this->request->query()->get($this->prg->tokenQueryKey());
        if (is_string($prgToken) && $prgToken !== '') {
            // PRG: the token was set by middleware on the POST. We pull the
            // stored form data, process it, and then REDIRECT to the clean
            // URL (without the token query parameter). This is the canonical
            // Post-Redirect-Get pattern.
            //
            // Why redirect even on F5 / repeated visits to the same URL?
            //
            //   - The token is single-use. After we pull it, it's gone.
            //   - If the user presses F5, the browser re-requests the same
            //     URL with the same token. Without a redirect, processSubmission
            //     runs again with an empty pulled array, which makes the CSRF
            //     check fail with form name "settings_" (empty action). That's
            //     the bug you saw.
            //   - Some context that was built before processSubmission ran is
            //     now stale (e.g. theme picker showing the old theme because the
            //     session was just updated). A clean redirect re-builds it.
            $this->processSubmission($prgToken);

            $pageUrl = $this->urlGen->toPage($this->t->t('WORDING_SETTINGS', fallback: 'WORDING_SETTINGS'));
            Response::redirect($pageUrl)->send()->drainTo($this->collector);
            exit;
        }

        return $this->renderForm();
    }

    // -------------------------------------------------------------------------

    private function processSubmission(string $prgToken): void
    {
        $posted = $this->prg->pull($prgToken) ?? [];
        $action = self::mStr($posted, 'action', '');
        $hexId  = $this->session->userId();

        // CSRF is scoped per action
        $csrfKey   = 'settings_' . $action;
        $csrfToken = self::mStr($posted, '_csrf', '');
        $csrfResult = $this->csrf->verify($csrfKey, $csrfToken);
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);
            return;
        }

        switch ($action) {
            case 'change_username':
                $result = $this->userService->changeUsername(
                    $hexId, self::mStr($posted, 'username', ''),
                );
                $result->drainTo($this->collector);
                if ($result->isOk()) {
                    $this->session->updateUsername(self::mStr($posted, 'username', ''));
                }
                break;

            case 'change_display_name':
                $result = $this->userService->changeDisplayName(
                    $hexId, self::mStr($posted, 'display_name', ''),
                );
                $result->drainTo($this->collector);
                if ($result->isOk()) {
                    $this->session->updateDisplayName(self::mStr($posted, 'display_name', ''));
                }
                break;

            case 'change_recovery_email':
                $newEmail = self::mStr($posted, 'email', '');
                $result = $this->userService->changeRecoveryEmail($hexId, $newEmail);
                $result->drainTo($this->collector);
                if ($result->isOk() && $newEmail !== '') {
                    // Confirm ownership of the new address with a verification link.
                    $this->sendVerificationTo($hexId, $newEmail, UserService::TOKEN_EMAIL_CHANGE);
                }
                break;

            case 'change_password':
                // Only skip the current-password check for a FRESH, session-bound,
                // one-shot recovery grant (set when the user arrived via a valid
                // recovery link this session) — NOT for any historically-used
                // recovery token, which previously left the check bypassable
                // indefinitely for a hijacked session (F-05).
                $resetUntil  = $_SESSION['_pw_reset_until'] ?? 0;
                $tokenUnlock = is_int($resetUntil) && $resetUntil > time();
                $result = $this->userService->changePassword(
                    $hexId,
                    self::mStr($posted, 'old_password', ''),
                    self::mStr($posted, 'password', ''),
                    self::mStr($posted, 'repeat', ''),
                    $tokenUnlock,
                );
                $result->drainTo($this->collector);
                if ($result->isOk() && $tokenUnlock) {
                    unset($_SESSION['_pw_reset_until']); // one-shot
                }
                break;

            case 'verify_email':
                // Resend a verification link to the user's stored recovery email.
                $emailResult = $this->userService->recoveryEmailFor($hexId);
                $emailResult->drainTo($this->collector);
                $addr = $emailResult->isOk() ? $emailResult->unwrap() : null;
                if (is_string($addr) && $addr !== '') {
                    $this->sendVerificationTo($hexId, $addr, UserService::TOKEN_EMAIL_VERIFY);
                }
                break;

            case 'set_avatar':
                $file = $this->request->files()->get('image');
                // FileBag::get() returns UploadedFile|array|null — only proceed for a single file
                if ($file instanceof \AstrX\Http\UploadedFile) {
                    $result = $this->avatarService->setAvatar($hexId, $file);
                    $result->drainTo($this->collector);
                    if ($result->isOk()) {
                        $this->session->updateAvatar(true);
                    }
                }
                break;

            case 'remove_avatar':
                $result = $this->avatarService->removeAvatar($hexId);
                $result->drainTo($this->collector);
                if ($result->isOk()) {
                    $this->session->updateAvatar(false);
                }
                break;

            case 'change_theme':
                // User chose a theme on their settings page. Validate against the
                // discovered theme list (security: prevents directory traversal).
                // Empty string is allowed and means "revert to global default".
                $theme = self::mStr($posted, 'theme', '');
                if ($theme !== '' && !$this->themeService->themeExists($theme)) {
                    // No flash on this controller — matches the existing pattern
                    // (all error feedback flows through diagnostics, which the
                    // template renders inline via the diagnostics panel).
                    $this->emit(new \AstrX\User\Diagnostic\InvalidThemeDiagnostic(
                        'astrx.user/invalid_theme',
                        \AstrX\Result\DiagnosticLevel::WARNING,
                    ));
                    break;
                }
                $result = $this->userService->changeTheme($hexId, $theme);
                $result->drainTo($this->collector);
                if ($result->isOk()) {
                    // Live-update the session so the new theme applies immediately
                    // on the redirect that follows this PRG dispatch.
                    $this->session->updateTheme($theme);
                }
                break;

            case 'create_api_key':
                // Gate: only users with Permission::API_KEY_CREATE may create keys.
                // Configured in Auth.config.php — by default USER and MOD have it,
                // GUEST does not. Removing it from the USER grant locks all key
                // creation behind admin provisioning (DB insert by an admin).
                if ($this->gate->cannot(Permission::API_KEY_CREATE)) {
                    $this->emit(new \AstrX\Api\Diagnostic\InvalidApiKeyDiagnostic(
                        'astrx.api/key_create_forbidden',
                        \AstrX\Result\DiagnosticLevel::WARNING,
                    ));
                    break;
                }
                $label = trim(self::mStr($posted, 'label', ''));
                if ($label === '') {
                    $this->emit(new \AstrX\Api\Diagnostic\InvalidApiKeyLabelDiagnostic(
                        'astrx.api/key_label_required',
                        \AstrX\Result\DiagnosticLevel::WARNING,
                    ));
                    break;
                }
                if (strlen($label) > 64) {
                    $this->emit(new \AstrX\Api\Diagnostic\InvalidApiKeyLabelDiagnostic(
                        'astrx.api/key_label_too_long',
                        \AstrX\Result\DiagnosticLevel::WARNING,
                    ));
                    break;
                }
                $result = $this->apiKeys->create($hexId, $label);
                $result->drainTo($this->collector);
                if ($result->isOk()) {
                    // The raw key must be shown to the user EXACTLY ONCE
                    // because we don't keep it after this point. Park it in
                    // the session under a one-shot flag that renderForm()
                    // reads and clears below.
                    $_SESSION['_new_api_key']       = $result->unwrap();
                    $_SESSION['_new_api_key_label'] = $label;
                }
                break;

            case 'revoke_api_key':
                if ($this->gate->cannot(Permission::API_KEY_REVOKE)) {
                    $this->emit(new \AstrX\Api\Diagnostic\InvalidApiKeyDiagnostic(
                        'astrx.api/key_revoke_forbidden',
                        \AstrX\Result\DiagnosticLevel::WARNING,
                    ));
                    break;
                }
                $keyId = trim(self::mStr($posted, 'key_id', ''));
                if ($keyId !== '' && preg_match('/\A[0-9a-f]{32}\z/', $keyId) === 1) {
                    $this->apiKeys->revoke($keyId, $hexId);
                }
                break;

            case 'delete_account':
                // Users may choose soft_redact (keeps data) or hard_redact (wipes PII).
                // full_delete and keep_suspended are reserved for admins.
                $modeRaw    = self::mStr($posted, 'delete_mode', DeletionMode::SOFT_REDACT->value);
                $deleteMode = DeletionMode::tryFrom($modeRaw) ?? DeletionMode::SOFT_REDACT;
                if ($deleteMode === DeletionMode::FULL_DELETE
                    || $deleteMode === DeletionMode::KEEP_SUSPENDED) {
                    $deleteMode = DeletionMode::SOFT_REDACT;
                }
                $result = $this->userService->delete(
                    hexId:    $hexId,
                    mode:     $deleteMode,
                    password: self::mStr($posted, 'password', ''),
                );
                $result->drainTo($this->collector);
                if ($result->isOk()) {
                    $this->session->logout();
                    session_destroy();
                    Response::redirect($this->urlGen->toPage($this->t->t('WORDING_MAIN')))
                        ->send()->drainTo($this->collector);
                    exit;
                }
                break;
        }
    }

    /** @return Result<mixed> */
    private function renderForm(): Result
    {
        $hexId   = $this->session->userId();
        // Use UrlGenerator for self-URL so it works in both rewrite and query mode.
        $pageUrl = $this->urlGen->toPage($this->t->t('WORDING_SETTINGS', fallback: 'WORDING_SETTINGS'));

        // Generate CSRF tokens for each form action
        $actions = [
            'change_username', 'change_display_name', 'change_recovery_email',
            'change_password', 'verify_email', 'set_avatar', 'remove_avatar',
            'delete_account', 'change_theme', 'create_api_key', 'revoke_api_key',
        ];
        $csrfTokens = [];
        $prgIds     = [];
        foreach ($actions as $action) {
            $csrfTokens[$action] = $this->csrf->generate('settings_' . $action);
            $prgIds[$action]     = $this->prg->createId($pageUrl);
        }

        $hasAvatar       = $this->session->hasAvatar();
        $avatarUrl       = $this->urlGen->toPage('avatar') . '?uid=' . $hexId;
        // Hide the old-password field only for a fresh, session-bound recovery
        // unlock — must match the change_password enforcement (F-05); otherwise a
        // stale used token would hide the field while the backend still demands it.
        $resetUntil      = $_SESSION['_pw_reset_until'] ?? 0;
        $tokenUnlock     = is_int($resetUntil) && $resetUntil > time();
        $isVerified      = $this->session->isVerified();

        $this->ctx->set('csrf',              $csrfTokens);
        $this->ctx->set('prg',               $prgIds);
        $this->ctx->set('username',          $this->session->username());
        $this->ctx->set('display_name',      $this->session->displayName());
        $this->ctx->set('has_avatar',        $hasAvatar);
        $this->ctx->set('avatar_url',        $avatarUrl);
        $this->ctx->set('token_unlock',      $tokenUnlock);
        $this->ctx->set('is_verified',       $isVerified);
        $this->ctx->set('show_mailbox',      $this->userService->requireEmail());
        $this->ctx->set('show_email',        $this->userService->requireRecoveryEmail());
        $this->ctx->set('show_display_name', $this->userService->requireDisplayName());
        $this->ctx->set('show_avatar',       true);
        $this->ctx->set('max_avatar_mb',     1); // TODO: from AvatarService config

        // ── API keys (fix100) ───────────────────────────────────────────
        // List the user's current keys for the management UI. The raw key
        // is NEVER stored — keys here are "id, label, created, last_used,
        // expires, revoked" only. The raw value of a JUST-CREATED key (if
        // any) is in $_SESSION as a one-shot flag and is shown once.
        $keysResult = $this->apiKeys->listForUser($hexId);
        $keysResult->drainTo($this->collector);
        $keys = $keysResult->isOk() ? $keysResult->unwrap() : [];

        // Normalise rows for the template: stringify timestamps, mark revoked.
        $normalisedKeys = [];
        foreach ($keys as $k) {
            $createdTs = isset($k['created_ts']) && is_numeric($k['created_ts']) ? (int) $k['created_ts'] : 0;
            $lastUsed  = isset($k['last_used_ts']) && is_numeric($k['last_used_ts']) ? (int) $k['last_used_ts'] : 0;
            $expiresTs = isset($k['expires_ts']) && is_numeric($k['expires_ts']) ? (int) $k['expires_ts'] : 0;
            $normalisedKeys[] = [
                'id'           => isset($k['id']) && is_scalar($k['id']) ? (string)$k['id'] : '',
                'label'        => isset($k['label']) && is_scalar($k['label']) ? (string)$k['label'] : '',
                'created_at'   => $createdTs > 0 ? gmdate('Y-m-d H:i', $createdTs) : '',
                'last_used_at' => $lastUsed  > 0 ? gmdate('Y-m-d H:i', $lastUsed)  : '—',
                'expires_at'   => $expiresTs > 0 ? gmdate('Y-m-d H:i', $expiresTs) : '',
                'has_expiry'   => $expiresTs > 0,
                'revoked'      => !empty($k['revoked']),
            ];
        }
        $this->ctx->set('api_keys',          $normalisedKeys);
        $this->ctx->set('has_api_keys',      $normalisedKeys !== []);

        // One-shot raw key display: if the previous request just created a key,
        // surface it now and clear from session so refreshing doesn't show it again.
        $newKeyRaw   = isset($_SESSION['_new_api_key'])       && is_string($_SESSION['_new_api_key'])
            ? $_SESSION['_new_api_key'] : '';
        $newKeyLabel = isset($_SESSION['_new_api_key_label']) && is_string($_SESSION['_new_api_key_label'])
            ? $_SESSION['_new_api_key_label'] : '';
        unset($_SESSION['_new_api_key'], $_SESSION['_new_api_key_label']);
        $this->ctx->set('new_api_key',         $newKeyRaw);
        $this->ctx->set('new_api_key_label',   $newKeyLabel);
        $this->ctx->set('show_new_api_key',    $newKeyRaw !== '');

        // Gate the create form: only render it when the user has the permission.
        // The list of existing keys + the revoke button still show because they
        // refer to the user's OWN keys (the user can always see and manage what
        // they own — independent of the create permission, which is a separate
        // capability some installs may want to lock to admins).
        $canCreateKeys = $this->gate->can(Permission::API_KEY_CREATE);
        $canRevokeKeys = $this->gate->can(Permission::API_KEY_REVOKE);
        $this->ctx->set('show_api_key_create', $canCreateKeys);
        $this->ctx->set('show_api_key_revoke', $canRevokeKeys);

        // Theme picker — only show if admin allows user override.
        $allowOverride = $this->themeService->allowUserOverride();
        $themes        = [];
        if ($allowOverride) {
            $currentTheme = $this->session->userTheme();
            foreach ($this->themeService->discoverThemes() as $tDef) {
                $tDef['active'] = ($tDef['key'] === $currentTheme);
                $themes[]       = $tDef;
            }
        }
        $this->ctx->set('show_theme_picker', $allowOverride);
        $this->ctx->set('themes',            $themes);
        $this->ctx->set('current_theme',     $this->session->userTheme());
        $this->ctx->set('theme_default_active', $this->session->userTheme() === '');

        $this->setI18n();
        return $this->ok();
    }

    private function setI18n(): void
    {
        $this->ctx->set('settings_heading',        $this->t->t('user.settings.heading'));
        $this->ctx->set('settings_avatar',         $this->t->t('user.settings.avatar'));
        $this->ctx->set('settings_set_avatar',     $this->t->t('user.settings.set_avatar'));
        $this->ctx->set('settings_remove_avatar',  $this->t->t('user.settings.remove_avatar'));
        $this->ctx->set('settings_max_size',       $this->t->t('user.settings.max_size'));
        $this->ctx->set('settings_display_name',   $this->t->t('user.settings.display_name'));
        $this->ctx->set('settings_new_display_name',$this->t->t('user.settings.new_display_name'));
        $this->ctx->set('settings_recovery_email', $this->t->t('user.settings.recovery_email'));
        $this->ctx->set('settings_new_email',      $this->t->t('user.settings.new_email'));
        $this->ctx->set('settings_username',       $this->t->t('user.settings.username'));
        $this->ctx->set('settings_new_username',   $this->t->t('user.settings.new_username'));
        $this->ctx->set('settings_password',       $this->t->t('user.settings.password'));
        $this->ctx->set('settings_old_password',   $this->t->t('user.field.old_password'));
        $this->ctx->set('settings_new_password',   $this->t->t('user.field.password'));
        $this->ctx->set('settings_repeat',         $this->t->t('user.field.repeat'));
        $this->ctx->set('settings_verify_email',   $this->t->t('user.settings.verify_email'));
        $this->ctx->set('settings_verify_desc',    $this->t->t('user.settings.verify_desc'));
        $this->ctx->set('settings_delete',         $this->t->t('user.settings.delete'));
        $this->ctx->set('settings_delete_confirm', $this->t->t('user.settings.delete_confirm'));
        $this->ctx->set('settings_submit',         $this->t->t('user.settings.submit'));
        $this->ctx->set('settings_theme',          $this->t->t('user.settings.theme'));
        $this->ctx->set('settings_theme_desc',     $this->t->t('user.settings.theme_desc'));
        $this->ctx->set('settings_theme_default',  $this->t->t('user.settings.theme_default'));

        // API keys lang
        $this->ctx->set('settings_api_keys',           $this->t->t('user.settings.api_keys'));
        $this->ctx->set('settings_api_keys_desc',      $this->t->t('user.settings.api_keys_desc'));
        $this->ctx->set('settings_api_keys_label',     $this->t->t('user.settings.api_keys_label'));
        $this->ctx->set('settings_api_keys_created',   $this->t->t('user.settings.api_keys_created'));
        $this->ctx->set('settings_api_keys_last_used', $this->t->t('user.settings.api_keys_last_used'));
        $this->ctx->set('settings_api_keys_expires',   $this->t->t('user.settings.api_keys_expires'));
        $this->ctx->set('settings_api_keys_status',    $this->t->t('user.settings.api_keys_status'));
        $this->ctx->set('settings_api_keys_actions',   $this->t->t('user.settings.api_keys_actions'));
        $this->ctx->set('settings_api_keys_active',    $this->t->t('user.settings.api_keys_active'));
        $this->ctx->set('settings_api_keys_revoked',   $this->t->t('user.settings.api_keys_revoked'));
        $this->ctx->set('settings_api_keys_revoke',    $this->t->t('user.settings.api_keys_revoke'));
        $this->ctx->set('settings_api_keys_create',    $this->t->t('user.settings.api_keys_create'));
        $this->ctx->set('settings_api_keys_new_label_ph', $this->t->t('user.settings.api_keys_new_label_ph'));
        $this->ctx->set('settings_api_keys_none',      $this->t->t('user.settings.api_keys_none'));
        $this->ctx->set('settings_api_keys_save_now',  $this->t->t('user.settings.api_keys_save_now'));
        $this->ctx->set('settings_api_keys_save_warn', $this->t->t('user.settings.api_keys_save_warn'));
        $this->ctx->set('settings_api_keys_no_permission', $this->t->t('user.settings.api_keys_no_permission'));
        $this->ctx->set('field_current_value',     $this->t->t('user.settings.current_value'));
    }
}
