<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Captcha\CaptchaService;
use AstrX\Mail\EmailService;
use AstrX\User\UserRepository;
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
use AstrX\Config\InjectConfig;
use AstrX\User\UserSession;

/**
 * Registration form controller.
 * Email sending (verification token) is stubbed — wire PHPMailer when ready.
 */
final class RegisterController extends AbstractController
{
    private const FORM = 'register';

    // Fix 7.3: cached config so duplicated reads stay in sync.
    private bool $mailboxIsUsername = false;

    #[InjectConfig('mailbox_is_username')]
    public function setMailboxIsUsername(bool $v): void
    {
        $this->mailboxIsUsername = $v;
    }

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
        private readonly EmailService          $emailService,
        private readonly UserRepository        $userRepo,
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
        // Fix 7.3: use cached config (set once via #[InjectConfig]).
        $mailbox     = $this->mailboxIsUsername
            ? $username
            : self::mStr($posted, 'mailbox', '');
        $email       = self::mStr($posted, 'email', '');
        $displayName = self::mStr($posted, 'display_name', '');
        $month       = is_numeric($posted['month'] ?? null) ? (int) $posted['month'] : null;
        $day         = is_numeric($posted['day']   ?? null) ? (int) $posted['day']   : null;
        $year        = is_numeric($posted['year']  ?? null) ? (int) $posted['year']  : null;
        $csrfToken   = self::mStr($posted, '_csrf', '');
        $captchaId   = self::mStr($posted, 'captcha_id', '');
        $captchaText = self::mStr($posted, 'captcha_text', '');
        $termsChecked     = self::mBool($posted, 'terms_accepted');
        $dataUsageChecked = self::mBool($posted, 'data_usage_accepted');

        $csrfResult = $this->csrf->verify(self::FORM, $csrfToken);
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);
            return $this->renderForm($username, $mailbox, $email, $displayName);
        }

        // Fix 7.2: captcha check moved BEFORE consent — captchas are one-shot
        // (deleted on verify), so a consent failure must not consume the
        // captcha and leave the user stuck with "already used" on retry.
        $captchaSubmitted = $captchaId !== '';
        $captchaResult    = $this->userService->shouldShowCaptcha(self::FORM);
        $policyRequires   = $captchaResult->isOk() && (bool) $captchaResult->unwrap();
        if ($captchaSubmitted || $policyRequires) {
            $verifyResult = $this->captchaService->verify($captchaId, $captchaText);
            if (!$verifyResult->isOk()) {
                $verifyResult->drainTo($this->collector);
                return $this->renderForm($username, $mailbox, $email, $displayName);
            }
        }

        // Validate consent checkboxes if they are required.
        if ($this->config->getConfigBool('RegisterConsent', 'require_terms', false)
            && !$termsChecked) {
            $this->flash->set('error', $this->t->t('user.register.terms_required'));
            return $this->renderForm($username, $mailbox, $email, $displayName);
        }
        if ($this->config->getConfigBool('RegisterConsent', 'require_data_usage', false)
            && !$dataUsageChecked) {
            $this->flash->set('error', $this->t->t('user.register.data_usage_required'));
            return $this->renderForm($username, $mailbox, $email, $displayName);
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

        // --- Email verification (fix114) ---
        //
        // Decision tree:
        //   (a) Email will be sent: user provided an address AND the admin
        //       enabled send_verification_email. Generate a token, send the
        //       email, user stays verified=0 until they click the link.
        //   (b) No email will be sent (either no address provided, or admin
        //       disabled verification globally): auto-set verified=1. Without
        //       this, the account would be created in a state where the user
        //       cannot verify and (if allow_login_non_verified_users=false)
        //       cannot log in either — a broken stuck state.
        //
        // Per the agreed default-B policy: if the EMAIL SEND fails, the user
        // account stays created and a diagnostic is emitted. The user can
        // request another verification email later, or an admin can
        // resend / mark them verified manually.
        $emailWillBeSent = $email !== ''
            && $this->userService->sendVerificationEmail();

        if ($emailWillBeSent) {
            $tokenResult = $this->userService->generateToken(
                $newHexId,
                \AstrX\User\UserService::TOKEN_EMAIL_VERIFY,
            );
            $tokenResult->drainTo($this->collector);

            if ($tokenResult->isOk()) {
                /** @var array{token:string,user_id:string,expires_at:int} $tok */
                $tok = $tokenResult->unwrap();
                $sendResult = $this->emailService->sendVerificationEmail(
                    toAddress: $email,
                    toName:    $displayName !== '' ? $displayName : $username,
                    username:  $username,
                    token:     $tok['token'],
                    userHexId: $newHexId,
                );
                $sendResult->drainTo($this->collector);
                // Default-B: account remains created regardless of send result.
            }
        } else {
            // No verification path exists for this account — auto-verify so
            // the user can actually log in.
            $verifyResult = $this->userRepo->setVerified($newHexId);
            $verifyResult->drainTo($this->collector);
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
        // Iframe-reloadable captcha — see LoginController for the rationale.
        $captchaFrameUrl = $captchaId !== ''
            ? $this->urlGen->toPage($this->t->t('WORDING_CAPTCHA_FRAME'))
                . '?cid=' . $captchaId
            : '';
        $this->ctx->set('captcha_frame_url', $captchaFrameUrl);
        $this->ctx->set('has_captcha_frame', $captchaFrameUrl !== '');
        // fix113 set this; lost during fix114's email-integration patch — re-added in fix117.
        // Captcha translation domain holds 'captcha.reload'. Without
        // this loadDomain call, t() emits a Missing-translation diagnostic
        // (the fallback still displays correctly, just noise in logs).
        $this->t->loadDomain(\AstrX\Support\langDir(), 'Captcha');
        $this->ctx->set('captcha_reload_label', $this->t->t('captcha.reload', fallback: 'New captcha'));
        $this->ctx->set('captcha_image',      $captchaB64);
        $this->ctx->set('show_mailbox', $this->userService->requireEmail() && !$this->mailboxIsUsername);
        $this->ctx->set('show_email',         $this->userService->requireRecoveryEmail());
        $this->ctx->set('show_display_name',  $this->userService->requireDisplayName());
        $this->ctx->set('show_birth_date',    $this->userService->requireBirthDate());
        $this->ctx->set('registrations_closed', false);
        $this->ctx->set('login_url',          $this->urlGen->toPage($this->t->t('WORDING_LOGIN')));

        // Consent checkboxes
        $showTerms     = $this->config->getConfigBool('RegisterConsent', 'require_terms',      false);
        $showDataUsage = $this->config->getConfigBool('RegisterConsent', 'require_data_usage', false);
        $termsUrl      = $this->config->getConfigString('RegisterConsent', 'terms_url',        '');
        $dataUsageUrl  = $this->config->getConfigString('RegisterConsent', 'data_usage_url',   '');
        $this->ctx->set('show_terms',         $showTerms);
        $this->ctx->set('show_data_usage',    $showDataUsage);
        $this->ctx->set('terms_url',          $termsUrl);
        $this->ctx->set('data_usage_url',     $dataUsageUrl);

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
        $this->ctx->set('reg_closed_msg',     $this->t->t('user.register.closed'));
        $this->ctx->set('captcha_label',      $this->t->t('user.captcha.label'));
        $this->ctx->set('reg_terms_label',    $this->t->t('user.register.terms_label'));
        $this->ctx->set('reg_data_usage_label', $this->t->t('user.register.data_usage_label'));
    }
}
