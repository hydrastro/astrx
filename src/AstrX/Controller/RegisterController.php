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
use AstrX\Config\Config;
use AstrX\User\UserService;
use AstrX\User\UserSession;

/**
 * Registration form controller.
 * Email sending (verification token) is stubbed — wire PHPMailer when ready.
 */
final class RegisterController extends AbstractController
{
    private const FORM = 'register';

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
        private readonly Config                $config,
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

        $username    = self::mStr($posted, 'username', '');
        $password    = self::mStr($posted, 'password', '');
        $repeat      = self::mStr($posted, 'repeat', '');
        $mailbox     = self::mStr($posted, 'mailbox', '');
        $email       = self::mStr($posted, 'email', '');
        $displayName = self::mStr($posted, 'display_name', '');
        $month       = is_numeric($posted['month'] ?? null) ? (int) $posted['month'] : null;
        $day         = is_numeric($posted['day']   ?? null) ? (int) $posted['day']   : null;
        $year        = is_numeric($posted['year']  ?? null) ? (int) $posted['year']  : null;
        $csrfToken   = self::mStr($posted, '_csrf', '');
        $captchaId   = self::mStr($posted, 'captcha_id', '');
        $captchaText = self::mStr($posted, 'captcha_text', '');

        $csrfResult = $this->csrf->verify(self::FORM, $csrfToken);
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);
            return $this->renderForm($username, $mailbox, $email, $displayName);
        }

        $captchaResult = $this->userService->shouldShowCaptcha(self::FORM);
        $needsCaptcha  = $captchaResult->isOk() && (bool) $captchaResult->unwrap();
        if ($needsCaptcha) {
            $verifyResult = $this->captchaService->verify($captchaId, $captchaText);
            if (!$verifyResult->isOk()) {
                $verifyResult->drainTo($this->collector);
                return $this->renderForm($username, $mailbox, $email, $displayName);
            }
        }

        $registerResult = $this->userService->register(
            $username, $password, $repeat,
            $mailbox ?: null, $email ?: null, $displayName ?: null,
            $month, $day, $year,
        );

        if (!$registerResult->isOk()) {
            $registerResult->drainTo($this->collector);
            return $this->renderForm($username, $mailbox, $email, $displayName);
        }
        $newHexId = $registerResult->unwrap();

        // --- Email verification token ---
        // If unverified users cannot log in, generate a token and send the verification email.
        // TODO: replace the commented-out block with a real PHPMailer call.
        if (!$this->userService->allowLoginNonVerifiedUsers()) {
            $tokenResult = $this->userService->generateToken($newHexId, \AstrX\User\UserService::TOKEN_EMAIL_VERIFY);
            $tokenResult->drainTo($this->collector);
            // if ($tokenResult->isOk()) {
            //     /** @var array<string,mixed> */
     $data = $tokenResult->unwrap();
            //     $link = ... build verify URL from $data['token'] and $data['user_id'] ...
            //     $mailer->send($email, $link);
            // }
        }

        $this->flash->set('success', $this->t->t('user.register.success'));

        // Redirect to login with success message via PRG
        Response::redirect($this->urlGen->toPage($this->t->t('WORDING_LOGIN')))
            ->send()->drainTo($this->collector);
        exit;
    }

    /** @return Result<mixed> */
    private function renderForm(
        string $username    = '',
        string $mailbox     = '',
        string $email       = '',
        string $displayName = '',
    ): Result {
        if (!$this->userService->allowRegister()) {
            $this->ctx->set('registrations_closed', true);
            $this->setI18n();
            return $this->ok();
        }

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

        $this->ctx->set('prg_id',            $prgId);
        $this->ctx->set('csrf_token',         $csrfToken);
        $this->ctx->set('username_value',     $username);
        $this->ctx->set('mailbox_value',      $mailbox);
        $this->ctx->set('email_value',        $email);
        $this->ctx->set('display_name_value', $displayName);
        $this->ctx->set('show_captcha',       $showCaptcha);
        $this->ctx->set('captcha_id',         $captchaId);
        $this->ctx->set('captcha_image',      $captchaB64);
        $mailboxIsUsername = $this->config->getConfigBool('WebmailService', 'mailbox_is_username', false);
        $this->ctx->set('show_mailbox', $this->userService->requireEmail() && !$mailboxIsUsername);
        $this->ctx->set('show_email',         $this->userService->requireRecoveryEmail());
        $this->ctx->set('show_display_name',  $this->userService->requireDisplayName());
        $this->ctx->set('show_birth_date',    $this->userService->requireBirthDate());
        $this->ctx->set('registrations_closed', false);
        $this->ctx->set('login_url',          $this->urlGen->toPage($this->t->t('WORDING_LOGIN')));

        $this->setI18n();
        return $this->ok();
    }

    private function setI18n(): void
    {
        $this->ctx->set('reg_heading',      $this->t->t('user.register.heading'));
        $this->ctx->set('reg_description',  $this->t->t('user.register.description'));
        $this->ctx->set('reg_username',     $this->t->t('user.field.username'));
        $this->ctx->set('reg_password',     $this->t->t('user.field.password'));
        $this->ctx->set('reg_repeat',       $this->t->t('user.field.repeat'));
        $this->ctx->set('reg_mailbox',      $this->t->t('user.field.mailbox'));
        $this->ctx->set('reg_mailbox_hint', $this->t->t('user.field.mailbox_hint'));
        $this->ctx->set('reg_email',        $this->t->t('user.field.email'));
        $this->ctx->set('reg_display_name', $this->t->t('user.field.display_name'));
        $this->ctx->set('reg_birth_date',   $this->t->t('user.field.birth_date'));
        $this->ctx->set('reg_submit',       $this->t->t('user.register.submit'));
        $this->ctx->set('reg_back',         $this->t->t('user.register.back_to_login'));
        $this->ctx->set('reg_closed_msg',   $this->t->t('user.register.closed'));
        $this->ctx->set('captcha_label',    $this->t->t('user.captcha.label'));
    }
}
