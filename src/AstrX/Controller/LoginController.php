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
use AstrX\Session\PrgHandler;
use AstrX\Template\DefaultTemplateContext;
use AstrX\User\UserService;
use AstrX\User\UserSession;

/**
 * Login form controller.
 *
 * GET  — generate CSRF, check if captcha needed, render form.
 * POST — ContentManager intercepts, stores data in PRG, redirects back.
 * GET with ?_prg=token — pull PRG data, verify CSRF + captcha, attempt login.
 */
final class LoginController extends AbstractController
{
    private const FORM = 'login';

    public function __construct(
        DiagnosticsCollector                   $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request               $request,
        private readonly UserSession           $session,
        private readonly UserService           $userService,
        private readonly CaptchaService        $captchaService,
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
        // Already logged in → redirect to home
        if ($this->session->isLoggedIn()) {
            Response::redirect($this->urlGen->toPage($this->t->t('WORDING_USER_HOME')))
                ->send()->drainTo($this->collector);
            exit;
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

        $username   = is_string($posted['username']   ?? null) ? (string) $posted['username']   : '';
        $password   = is_string($posted['password']   ?? null) ? (string) $posted['password']   : '';
        $rememberMe = self::mBool($posted, 'remember_me');
        $csrfToken  = is_string($posted['_csrf']      ?? null) ? (string) $posted['_csrf']      : '';
        $captchaId  = is_string($posted['captcha_id'] ?? null) ? (string) $posted['captcha_id'] : '';
        $captchaText= is_string($posted['captcha_text']?? null) ? (string) $posted['captcha_text'] : '';

        // CSRF check
        $csrfResult = $this->csrf->verify(self::FORM, $csrfToken);
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);
            return $this->renderForm($username);
        }

        // Captcha check (before login attempt — don't reveal if user exists)
        $showCaptchaResult = $this->userService->shouldShowCaptcha(self::FORM, $username);
        $showCaptcha = $showCaptchaResult->isOk() && (bool) $showCaptchaResult->unwrap();

        if ($showCaptcha) {
            $captchaResult = $this->captchaService->verify($captchaId, $captchaText);
            if (!$captchaResult->isOk()) {
                $captchaResult->drainTo($this->collector);
                return $this->renderForm($username, true);
            }
        }

        // Login
        $loginResult = $this->userService->login($username, $password, $rememberMe);
        if (!$loginResult->isOk()) {
            $loginResult->drainTo($this->collector);
            // Check if captcha is NOW needed after this failure
            $showAfterResult = $this->userService->shouldShowCaptcha(self::FORM, $username);
            $showAfter = $showAfterResult->isOk() && (bool) $showAfterResult->unwrap();
            return $this->renderForm($username, $showAfter);
        }

        /** @var array{id:string,username:string,display_name:string,type:int,verified:bool|int,avatar:bool|int,mailbox?:string} $userData */
        $userData = $loginResult->unwrap();
        $this->session->login($userData);
        // Store cleartext password in the AES-encrypted session so webmail
        // can connect to IMAP without re-prompting the user.
        $this->session->storeImapPassword($password);

        Response::redirect($this->urlGen->toPage($this->t->t('WORDING_USER_HOME')))
            ->send()->drainTo($this->collector);
        exit;
    }

    /** @return Result<mixed> */
    private function renderForm(string $usernameValue = '', bool $showCaptcha = false): Result
    {
        $csrfToken    = $this->csrf->generate(self::FORM);
        $pageUrl      = $this->request->uri()->path();
        $prgId        = $this->prg->createId($pageUrl);

        // Captcha for initial render (ALWAYS policy or passed explicitly)
        if (!$showCaptcha) {
            $captchaResult = $this->userService->shouldShowCaptcha(self::FORM, $usernameValue);
            $showCaptcha = $captchaResult->isOk() && (bool) $captchaResult->unwrap();
        }

        $captchaId  = '';
        $captchaB64 = '';
        if ($showCaptcha) {
            $gen = $this->captchaService->generate();
            $gen->drainTo($this->collector);
            if ($gen->isOk()) {
                $captchaId  = $gen->unwrap()['id'];
                $captchaB64 = $gen->unwrap()['image_b64'];
            }
        }

        $this->ctx->set('prg_id',            $prgId);
        $this->ctx->set('csrf_token',         $csrfToken);
        $this->ctx->set('username_value',     $usernameValue);
        $this->ctx->set('show_captcha',       $showCaptcha);
        $this->ctx->set('captcha_id',         $captchaId);
        $this->ctx->set('captcha_image',      $captchaB64);
        // Iframe URL for reload-without-page-refresh captcha (fix104).
        // Templates may use {{captcha_frame_url}} inside <iframe src=...>.
        // Iframe-reloadable captcha. Two paired keys:
        //   captcha_frame_url  — the iframe src (empty when no captcha needed)
        //   has_captcha_frame  — boolean used by the partial's section guard.
        // The partial CAN'T guard on captcha_frame_url directly because Mustache
        // changes the current context inside `{{#string_var}}...{{/string_var}}`,
        // making nested `{{captcha_frame_url}}` lookups ambiguous and emitting
        // empty in this implementation. The boolean dodge keeps the lookup at
        // the parent context where the URL actually lives.
        $captchaFrameUrl = $captchaId !== ''
            ? $this->urlGen->toPage($this->t->t('WORDING_CAPTCHA_FRAME'))
                . '?cid=' . $captchaId
            : '';
        $this->ctx->set('captcha_frame_url', $captchaFrameUrl);
        $this->ctx->set('has_captcha_frame', $captchaFrameUrl !== '');
        // Label used by the parent-side reload link (external to the iframe
        // so it can't be clipped by overflow:hidden — fix113).
        // Captcha translation domain holds 'captcha.reload'. Without
        // this loadDomain call, t() emits a Missing-translation diagnostic
        // (the fallback still displays correctly, just noise in logs).
        $this->t->loadDomain(\AstrX\Support\langDir(), 'Captcha');
        $this->ctx->set('captcha_reload_label', $this->t->t('captcha.reload', fallback: 'New captcha'));
        $this->ctx->set('register_url',       $this->urlGen->toPage($this->t->t('WORDING_REGISTER')));
        $this->ctx->set('recover_url',        $this->urlGen->toPage($this->t->t('WORDING_RECOVER')));
        $this->ctx->set('show_recover',       $this->userService->requireRecoveryEmail());

        $this->setI18n();

        return $this->ok();
    }

    private function setI18n(): void
    {
        $this->ctx->set('login_heading',      $this->t->t('user.login.heading'));
        $this->ctx->set('login_username',     $this->t->t('user.field.username'));
        $this->ctx->set('login_password',     $this->t->t('user.field.password'));
        $this->ctx->set('login_remember_me',  $this->t->t('user.login.remember_me'));
        $this->ctx->set('login_submit',       $this->t->t('user.login.submit'));
        $this->ctx->set('login_lost_password',$this->t->t('user.login.lost_password'));
        $this->ctx->set('login_need_account', $this->t->t('user.login.need_account'));
        $this->ctx->set('login_register',     $this->t->t('user.login.register'));
        $this->ctx->set('captcha_label',      $this->t->t('user.captcha.label'));
    }
}
