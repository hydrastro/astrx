<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Captcha\CaptchaService;
use AstrX\Mail\EmailService;
use AstrX\Csrf\CsrfHandler;
use AstrX\Http\Request;
use AstrX\Http\Response;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\Session\FlashBag;
use AstrX\Session\PrgHandler;
use AstrX\Template\DefaultTemplateContext;
use AstrX\User\UserService;
use AstrX\User\UserSession;
use AstrX\User\Diagnostic\UserNotFoundDiagnostic;

/**
 * Password recovery controller.
 * Generates a recovery token and would email it. Email sending is stubbed.
 */
final class RecoverController extends AbstractController
{
    private const FORM       = 'recover';
    private const RESET_FORM = 'recover_reset';

    public function __construct(
        DiagnosticsCollector                   $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request               $request,
        private readonly UserSession           $session,
        private readonly UserService           $userService,
        private readonly CaptchaService        $captchaService,
        private readonly CsrfHandler           $csrf,
        private readonly PrgHandler            $prg,
        private readonly FlashBag              $flash,
        private readonly UrlGenerator          $urlGen,
        private readonly Translator            $t,
        private readonly EmailService          $emailService,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        if ($this->session->isLoggedIn()) {
            Response::redirect($this->urlGen->toPage($this->t->t('WORDING_USER_HOME')))
                ->send()->drainTo($this->collector);
            exit;
        }

        // Recovery set-password step: reached after clicking a valid recovery
        // link, which granted a one-shot _pw_reset_uid capability (NO session).
        // Render/process the "choose a new password" form here, logged-out; a
        // session is only established when the user logs in fresh afterwards.
        $resetUid   = $_SESSION['_pw_reset_uid']   ?? null;
        $resetUntil = $_SESSION['_pw_reset_until'] ?? 0;
        if (is_string($resetUid) && $resetUid !== ''
            && is_int($resetUntil) && $resetUntil > time()) {
            $prgToken = $this->request->query()->get($this->prg->tokenQueryKey());
            if (is_string($prgToken) && $prgToken !== '') {
                return $this->processReset($prgToken, $resetUid);
            }
            return $this->renderResetForm();
        }

        if (!$this->userService->requireRecoveryEmail()) {
            $this->ctx->set('recovery_unavailable', true);
            $this->setI18n();
            return $this->ok();
        }

        $prgToken = $this->request->query()->get($this->prg->tokenQueryKey());
        if (is_string($prgToken) && $prgToken !== '') {
            return $this->processSubmission($prgToken);
        }

        return $this->renderForm();
    }

    // -------------------------------------------------------------------------

    /** @return Result<mixed> */
    private function processSubmission(string $prgToken): Result
    {
        $posted = $this->prg->pull($prgToken) ?? [];

        $identifier  = self::mStr($posted, 'username_or_email', '');
        $csrfToken   = self::mStr($posted, '_csrf', '');
        $captchaId   = self::mStr($posted, 'captcha_id', '');
        $captchaText = self::mStr($posted, 'captcha_text', '');

        $csrfResult = $this->csrf->verify(self::FORM, $csrfToken);
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);
            return $this->renderForm();
        }

        $captchaResult = $this->userService->shouldShowCaptcha(self::FORM);
        if ($captchaResult->isOk() && (bool) $captchaResult->unwrap()) {
            $verifyResult = $this->captchaService->verify($captchaId, $captchaText);
            if (!$verifyResult->isOk()) {
                $verifyResult->drainTo($this->collector);
                return $this->renderForm();
            }
        }

        $recoveryResult = $this->userService->initiateRecovery($identifier);
        if (!$recoveryResult->isOk()) {
            // Distinguish "user not found" from genuine failures (e.g. DB error).
            // Previously the success path 302-redirected while not-found
            // re-rendered the form (200) — a differential that leaks account
            // existence. Treat not-found as a NON-LEAKING success: keep the
            // diagnostic server-side only and fall through to the exact same
            // generic flash + redirect the success path performs below.
            $isNotFound = false;
            foreach ($recoveryResult->diagnostics() as $d) {
                if ($d instanceof UserNotFoundDiagnostic) {
                    $isNotFound = true;
                    break;
                }
            }
            $recoveryResult->drainTo($this->collector);
            if (!$isNotFound) {
                // Genuine error (DB failure, empty input) — safe to surface.
                return $this->renderForm();
            }
            // User not found → mirror the success path exactly.
            $this->flash->set('info', $this->t->t('user.recover.sent'));
            Response::redirect($this->urlGen->toPage($this->t->t('WORDING_LOGIN')))
                ->send()->drainTo($this->collector);
            exit;
        }
        $userRow = $recoveryResult->unwrap();
        $tokenResult = $this->userService->generateToken(
            (is_scalar($userRow['id']) ? (string)$userRow['id'] : ''),
            UserService::TOKEN_RECOVER,
        );
        $tokenResult->drainTo($this->collector);

        if ($tokenResult->isOk() && $this->userService->sendPasswordResetEmail()) {
            /** @var array{token:string,user_id:string,expires_at:int} $tokenData */
            $tokenData = $tokenResult->unwrap();

            // Extract the user's email + display info from the row we already
            // pulled in initiateRecovery. If the user has no email on file,
            // skip sending — the flash message below is still shown so we
            // don't leak account-existence either way.
            $userEmail   = is_scalar($userRow['email']    ?? null) ? (string) $userRow['email']    : '';
            $userName    = is_scalar($userRow['username'] ?? null) ? (string) $userRow['username'] : '';
            $userDisplay = is_scalar($userRow['display_name'] ?? null)
                ? (string) $userRow['display_name'] : $userName;

            if ($userEmail !== '') {
                $sendResult = $this->emailService->sendPasswordResetEmail(
                    toAddress: $userEmail,
                    toName:    $userDisplay !== '' ? $userDisplay : $userName,
                    username:  $userName,
                    token:     $tokenData['token'],
                    userHexId: $tokenData['user_id'],
                );
                $sendResult->drainTo($this->collector);
                // Default-B: diagnostic is collected; flash message below
                // shows the same generic text whether send succeeded or not
                // (avoids leaking account existence via differential UX).
            }
        }

        // Always show the same message regardless of whether the user exists (prevents enumeration)
        $this->flash->set('info', $this->t->t('user.recover.sent'));

        Response::redirect($this->urlGen->toPage($this->t->t('WORDING_LOGIN')))
            ->send()->drainTo($this->collector);
        exit;
    }

    /**
     * Process the recovery set-password submission. Validates CSRF + the one-shot
     * grant, sets the new password (no current-password needed — the recovery
     * token already proved control), burns the grant, and sends the user to log
     * in fresh. Deliberately does NOT auto-login: clicking a link never yields a
     * session, and the new session comes only from a clean login with the new
     * password.
     *
     * @return Result<mixed>
     */
    private function processReset(string $prgToken, string $resetUid): Result
    {
        $posted    = $this->prg->pull($prgToken) ?? [];
        $csrfToken = self::mStr($posted, '_csrf', '');

        $csrfResult = $this->csrf->verify(self::RESET_FORM, $csrfToken);
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);
            return $this->renderResetForm();
        }

        // Re-validate the grant at submit time — it may have expired between the
        // GET that rendered the form and this POST — and confirm it still binds
        // to the same uid.
        $stillUid   = $_SESSION['_pw_reset_uid']   ?? null;
        $stillUntil = $_SESSION['_pw_reset_until'] ?? 0;
        if (!(is_string($stillUid) && $stillUid === $resetUid
              && is_int($stillUntil) && $stillUntil > time())) {
            unset($_SESSION['_pw_reset_uid'], $_SESSION['_pw_reset_until']);
            $this->flash->set('error', $this->t->t('user.recover.reset_expired'));
            Response::redirect($this->urlGen->toPage($this->t->t('WORDING_LOGIN')))
                ->send()->drainTo($this->collector);
            exit;
        }

        $new    = self::mStr($posted, 'new_password', '');
        $repeat = self::mStr($posted, 'repeat_password', '');

        // tokenUnlock=true → changePassword skips the current-password check.
        $result = $this->userService->changePassword($resetUid, '', $new, $repeat, true);
        $result->drainTo($this->collector);
        if (!$result->isOk()) {
            return $this->renderResetForm();
        }

        // Success: burn the one-shot grant and require a fresh login.
        unset($_SESSION['_pw_reset_uid'], $_SESSION['_pw_reset_until']);
        $this->flash->set('success', $this->t->t('user.recover.reset_done'));
        Response::redirect($this->urlGen->toPage($this->t->t('WORDING_LOGIN')))
            ->send()->drainTo($this->collector);
        exit;
    }

    /** @return Result<mixed> */
    private function renderResetForm(): Result
    {
        $csrfToken = $this->csrf->generate(self::RESET_FORM);
        $pageUrl   = $this->request->uri()->path();
        $prgId     = $this->prg->createId($pageUrl);

        $this->ctx->set('reset_mode',           true);
        $this->ctx->set('prg_id',               $prgId);
        $this->ctx->set('csrf_token',           $csrfToken);
        $this->ctx->set('recovery_unavailable', false);
        $this->ctx->set('reset_heading',        $this->t->t('user.recover.reset_heading'));
        $this->ctx->set('reset_description',    $this->t->t('user.recover.reset_description'));
        $this->ctx->set('reset_new',            $this->t->t('user.recover.reset_new'));
        $this->ctx->set('reset_repeat',         $this->t->t('user.recover.reset_repeat'));
        $this->ctx->set('reset_submit',         $this->t->t('user.recover.reset_submit'));
        $this->ctx->set('login_url',            $this->urlGen->toPage($this->t->t('WORDING_LOGIN')));
        $this->ctx->set('recover_back',         $this->t->t('user.recover.back_to_login'));
        return $this->ok();
    }

    /** @return Result<mixed> */
    private function renderForm(): Result
    {
        $this->ctx->set('reset_mode', false);

        $csrfToken = $this->csrf->generate(self::FORM);
        $pageUrl   = $this->request->uri()->path();
        $prgId     = $this->prg->createId($pageUrl);

        $captchaResult = $this->userService->shouldShowCaptcha(self::FORM);
        $showCaptcha   = $captchaResult->isOk() && (bool) $captchaResult->unwrap();

        $captchaId = ''; $captchaB64 = '';
        if ($showCaptcha) {
            $gen = $this->captchaService->generate();
            $gen->drainTo($this->collector);
            if ($gen->isOk()) {
                $captchaId  = $gen->unwrap()['id'];
                $captchaB64 = $gen->unwrap()['image_b64'];
            }
        }

        $this->ctx->set('prg_id',               $prgId);
        $this->ctx->set('csrf_token',            $csrfToken);
        $this->ctx->set('show_captcha',          $showCaptcha);
        $this->ctx->set('captcha_id',            $captchaId);
        $this->ctx->set('captcha_image',         $captchaB64);
        $this->ctx->set('login_url',             $this->urlGen->toPage($this->t->t('WORDING_LOGIN')));
        $this->ctx->set('recovery_unavailable',  false);

        $this->setI18n();
        return $this->ok();
    }

    private function setI18n(): void
    {
        $this->ctx->set('recover_heading',      $this->t->t('user.recover.heading'));
        $this->ctx->set('recover_description',  $this->t->t('user.recover.description'));
        $this->ctx->set('recover_identifier',   $this->t->t('user.recover.identifier'));
        $this->ctx->set('recover_submit',       $this->t->t('user.recover.submit'));
        $this->ctx->set('recover_back',         $this->t->t('user.recover.back_to_login'));
        $this->ctx->set('recover_unavailable_msg', $this->t->t('user.recover.unavailable'));
        $this->ctx->set('captcha_label',        $this->t->t('user.captcha.label'));
    }
}
