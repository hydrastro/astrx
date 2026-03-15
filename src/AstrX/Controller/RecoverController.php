<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Captcha\CaptchaService;
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

/**
 * Password recovery controller.
 * Generates a recovery token and would email it. Email sending is stubbed.
 */
final class RecoverController extends AbstractController
{
    private const FORM = 'recover';

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
    ) {
        parent::__construct($collector);
    }

    public function handle(): Result
    {
        if ($this->session->isLoggedIn()) {
            Response::redirect($this->urlGen->toPage($this->t->t('WORDING_USER_HOME')))
                ->send()->drainTo($this->collector);
            exit;
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

    private function processSubmission(string $prgToken): Result
    {
        $posted = $this->prg->pull($prgToken) ?? [];

        $identifier  = (string) ($posted['username_or_email'] ?? '');
        $csrfToken   = (string) ($posted['_csrf']             ?? '');
        $captchaId   = (string) ($posted['captcha_id']        ?? '');
        $captchaText = (string) ($posted['captcha_text']      ?? '');

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
            $recoveryResult->drainTo($this->collector);
            return $this->renderForm();
        }

        $userRow = $recoveryResult->unwrap();
        $tokenResult = $this->userService->generateToken(
            (string) $userRow['id'],
            UserService::TOKEN_RECOVER,
        );
        $tokenResult->drainTo($this->collector);

        if ($tokenResult->isOk()) {
            $tokenData = $tokenResult->unwrap();
            // TODO: send email via PHPMailer with link:
            // /en/user?_token={token}&_uid={user_id}
            // For development: the token link is emitted as a NOTICE diagnostic.
            $link = $this->urlGen->toPage($this->t->t('WORDING_USER')) .
                    '?_token=' . rawurlencode($tokenData['token']) .
                    '&_uid=' . rawurlencode($tokenData['user_id']);
            // Emit as notice so it shows in the status bar during development
            // (remove / replace with real email sending before production)
            // $this->emitMailerNotice($link); — placeholder
        }

        // Always show the same message regardless of whether the user exists (prevents enumeration)
        $this->flash->set('info', $this->t->t('user.recover.sent'));

        Response::redirect($this->urlGen->toPage($this->t->t('WORDING_LOGIN')))
            ->send()->drainTo($this->collector);
        exit;
    }

    private function renderForm(): Result
    {
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