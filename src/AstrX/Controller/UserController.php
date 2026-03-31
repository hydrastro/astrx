<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Captcha\CaptchaService;
use AstrX\Captcha\CaptchaType;
use AstrX\Config\Config;
use AstrX\Csrf\CsrfHandler;
use AstrX\Http\Request;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\Session\PrgHandler;
use AstrX\Template\DefaultTemplateContext;
use AstrX\Mail\WebmailService;
use AstrX\User\UserService;
use AstrX\User\UserSession;

/**
 * User section root (/en/user or /it/utente).
 *
 * Renders directly rather than redirecting:
 *   - Logged in  → shows user home content
 *   - Not logged in → shows login form
 *
 * Token verification links (?_token=xxx&_uid=yyy) are still handled here
 * and redirect out after processing.
 */
final class UserController extends AbstractController
{
    private const LOGIN_FORM = 'login';

    public function __construct(
        DiagnosticsCollector                   $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request               $request,
        private readonly UserSession           $session,
        private readonly UserService           $userService,
        private readonly CaptchaService        $captchaService,
        private readonly Config                $config,
        private readonly CsrfHandler           $csrf,
        private readonly PrgHandler            $prg,
        private readonly UrlGenerator          $urlGen,
        private readonly Translator            $t,
        private readonly WebmailService        $webmail,
    ) {
        parent::__construct($collector);
    }

    public function handle(): Result
    {
        // --- Token verification (email links) --------------------------------
        $rawToken = $this->request->query()->get('_token');
        $hexUid   = $this->request->query()->get('_uid');

        if (is_string($rawToken) && $rawToken !== '' && is_string($hexUid) && $hexUid !== '') {
            $verifyResult = $this->userService->verifyToken($hexUid, $rawToken);
            $verifyResult->drainTo($this->collector);

            if ($verifyResult->isOk()) {
                $tokenType = $verifyResult->unwrap();

                if ($tokenType === UserService::TOKEN_DELETE) {
                    $this->userService->delete(
                        hexId:       $hexUid,
                        mode:        \AstrX\User\DeletionMode::SOFT_REDACT,
                        tokenUnlock: true,
                    );
                    $this->session->logout();
                } elseif (
                    $this->session->isLoggedIn() &&
                    $this->session->userId() === $hexUid
                ) {
                    $this->session->markVerified();
                }

                // Redirect to settings for recover/email actions, main for delete
                $dest = $tokenType === UserService::TOKEN_DELETE
                    ? $this->t->t('WORDING_MAIN')
                    : $this->t->t('WORDING_SETTINGS');

                header('Location: ' . $this->urlGen->toPage($dest));
                exit;
            }
            // Fall through on failed token — show normal page with errors visible
        }

        // --- PRG: login form submitted ----------------------------------------
        $prgToken = $this->request->query()->get($this->prg->tokenQueryKey());
        if (is_string($prgToken) && $prgToken !== '' && !$this->session->isLoggedIn()) {
            $this->processLoginSubmission($prgToken);
            if ($this->session->isLoggedIn()) {
                // Redirect to home after successful login
                header('Location: ' . $this->urlGen->toPage($this->t->t('WORDING_USER_HOME')));
                exit;
            }
            // Failed — fall through to re-render with errors
        }

        // --- Render -----------------------------------------------------------
        if ($this->session->isLoggedIn()) {
            $this->renderHome();
        } else {
            $this->renderLoginForm();
        }

        return $this->ok();
    }

    // -------------------------------------------------------------------------

    private function processLoginSubmission(string $prgToken): void
    {
        $posted = $this->prg->pull($prgToken) ?? [];

        $username    = is_string($posted['username']    ?? null) ? (string) $posted['username']    : '';
        $password    = is_string($posted['password']    ?? null) ? (string) $posted['password']    : '';
        $rememberMe  = self::mBool($posted, 'remember_me');
        $csrfToken   = is_string($posted['_csrf']       ?? null) ? (string) $posted['_csrf']       : '';
        $captchaId   = is_string($posted['captcha_id']  ?? null) ? (string) $posted['captcha_id']  : '';
        $captchaText = is_string($posted['captcha_text']?? null) ? (string) $posted['captcha_text']: '';

        $csrfResult = $this->csrf->verify(self::LOGIN_FORM, $csrfToken);
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);
            return;
        }

        $showCaptchaResult = $this->userService->shouldShowCaptcha(self::LOGIN_FORM, $username);
        if ($showCaptchaResult->isOk() && (bool) $showCaptchaResult->unwrap()) {
            $captchaResult = $this->captchaService->verify($captchaId, $captchaText);
            if (!$captchaResult->isOk()) {
                $captchaResult->drainTo($this->collector);
                return;
            }
        }

        $loginResult = $this->userService->login($username, $password, $rememberMe);
        if (!$loginResult->isOk()) {
            $loginResult->drainTo($this->collector);
            return;
        }

        /** @var array{id:string,username:string,display_name:string,type:int,verified:bool|int,avatar:bool|int,mailbox?:string} $userData */
        $userData = $loginResult->unwrap();
        $this->session->login($userData);

        // When the mail server is local (shares user DB with this app), the
        // user's login password is also the IMAP password.  Store it now so
        // the webmail page does not need to ask for it again.
        if ($this->webmail->mailserverIsLocal() && $password !== '') {
            $this->session->storeImapPassword($password);
        }
    }

    private function renderLoginForm(string $usernameValue = ''): void
    {
        $pageUrl = $this->request->uri()->path();
        $prgId   = $this->prg->createId($pageUrl);

        $captchaResult = $this->userService->shouldShowCaptcha(self::LOGIN_FORM, $usernameValue);
        $showCaptcha   = $captchaResult->isOk() && (bool) $captchaResult->unwrap();

        $captchaId = ''; $captchaB64 = '';
        if ($showCaptcha) {
            $loginDifficulty = CaptchaType::from(
                $this->config->getConfigInt('CaptchaRenderer', 'login_captcha_difficulty', CaptchaType::MEDIUM->value)
            );
            $gen = $this->captchaService->generateWithType($loginDifficulty);
            $gen->drainTo($this->collector);
            if ($gen->isOk()) {
                $captchaId  = $gen->unwrap()['id'];
                $captchaB64 = $gen->unwrap()['image_b64'];
            }
        }

        $this->ctx->set('logged_in',          false);
        $this->ctx->set('prg_id',             $prgId);
        $this->ctx->set('csrf_token',         $this->csrf->generate(self::LOGIN_FORM));
        $this->ctx->set('username_value',     $usernameValue);
        $this->ctx->set('show_captcha',       $showCaptcha);
        $this->ctx->set('captcha_id',         $captchaId);
        $this->ctx->set('captcha_image',      $captchaB64);
        $this->ctx->set('register_url',       $this->urlGen->toPage($this->t->t('WORDING_REGISTER')));
        $this->ctx->set('recover_url',        $this->urlGen->toPage($this->t->t('WORDING_RECOVER')));
        $this->ctx->set('show_recover',       $this->userService->requireRecoveryEmail());
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

    private function renderHome(): void
    {
        $this->ctx->set('logged_in',             true);
        $this->ctx->set('username',              $this->session->username());
        $this->ctx->set('user_welcome_heading',  $this->t->t('user.home.heading'));
        $this->ctx->set('user_welcome_body',     $this->t->t('user.home.body'));
        $this->ctx->set('user_profile_heading',  $this->t->t('user.home.profile_heading'));
        $this->ctx->set('user_profile_text',     $this->t->t('user.home.profile_text'));
        $this->ctx->set('user_settings_heading', $this->t->t('user.home.settings_heading'));
        $this->ctx->set('user_settings_text',    $this->t->t('user.home.settings_text'));
        $this->ctx->set('profile_url',           $this->urlGen->toPage($this->t->t('WORDING_PROFILE')));
        $this->ctx->set('settings_url',          $this->urlGen->toPage($this->t->t('WORDING_SETTINGS')));
        $this->ctx->set('logout_url',            $this->urlGen->toPage($this->t->t('WORDING_LOGOUT')));
    }
}
