<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Csrf\CsrfHandler;
use AstrX\Http\Request;
use AstrX\Http\Response;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\Session\PrgHandler;
use AstrX\Template\DefaultTemplateContext;
use AstrX\User\AvatarService;
use AstrX\User\DeletionMode;
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
    ) {
        parent::__construct($collector);
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
            $this->processSubmission($prgToken);
            // processSubmission always redirects or falls through to renderForm
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
                $result = $this->userService->changeRecoveryEmail(
                    $hexId, self::mStr($posted, 'email', ''),
                );
                $result->drainTo($this->collector);
                if ($result->isOk()) {
                    // TODO: send verification email for new address
                }
                break;

            case 'change_password':
                $tokenUnlock = $this->userService->hasUsedToken($hexId, UserService::TOKEN_RECOVER);
                $result = $this->userService->changePassword(
                    $hexId,
                    self::mStr($posted, 'old_password', ''),
                    self::mStr($posted, 'password', ''),
                    self::mStr($posted, 'repeat', ''),
                    $tokenUnlock,
                );
                $result->drainTo($this->collector);
                break;

            case 'verify_email':
                // Generate verification token and send email
                $tokenResult = $this->userService->generateToken($hexId, UserService::TOKEN_EMAIL_VERIFY);
                $tokenResult->drainTo($this->collector);
                if ($tokenResult->isOk()) {
                    /** @var array<string,mixed> */

                    $tokenData = $tokenResult->unwrap();
                    // TODO: send email. Dev notice: link below
                    // $link = urlGen->toPage('WORDING_USER') . '?_token=...'
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
            'delete_account',
        ];
        $csrfTokens = [];
        $prgIds     = [];
        foreach ($actions as $action) {
            $csrfTokens[$action] = $this->csrf->generate('settings_' . $action);
            $prgIds[$action]     = $this->prg->createId($pageUrl);
        }

        $hasAvatar       = $this->session->hasAvatar();
        $avatarUrl       = $this->urlGen->toPage('avatar') . '?uid=' . $hexId;
        $tokenUnlock     = $this->userService->hasUsedToken($hexId, UserService::TOKEN_RECOVER);
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
        $this->ctx->set('field_current_value',     $this->t->t('user.settings.current_value'));
    }
}
