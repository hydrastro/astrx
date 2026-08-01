<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Auth\TotpService;
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
 * Two-factor (TOTP) management for the logged-in user (/settings-2fa).
 *
 * Enable flow: "Set up" mints a secret (held in the session, NOT the DB, until
 * confirmed) shown as the base32 key + an otpauth:// URI; the user adds it to an
 * authenticator and confirms with a live code. Only then is it persisted and a
 * set of one-time recovery codes generated and shown ONCE. Disable requires a
 * current TOTP or recovery code, so a merely-borrowed session can't switch it off.
 * Any logged-in user manages only their own factor. No-JS, PRG + CSRF.
 */
final class TwofactorController extends AbstractController
{
    private const FORM = 'twofactor';
    private const ISSUER = 'AstrX';

    public function __construct(
        DiagnosticsCollector                    $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request                $request,
        private readonly UserSession            $session,
        private readonly UserService            $userService,
        private readonly TotpService            $totp,
        private readonly CsrfHandler            $csrf,
        private readonly PrgHandler             $prg,
        private readonly UrlGenerator           $urlGen,
        private readonly Translator             $t,
        private readonly FlashBag               $flash,
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

        $uid     = $this->session->userId();
        $selfUrl = $this->request->uri()->path();

        $prgToken = $this->request->query()->get($this->prg->tokenQueryKey());
        if (is_string($prgToken) && $prgToken !== '') {
            $this->processPost($this->prg->pull($prgToken) ?? [], $uid);
            Response::redirect($selfUrl)->send()->drainTo($this->collector);
            exit;
        }

        $info    = $this->userService->totpInfo($uid);
        $enabled = $info['enabled'];
        $setup   = is_string($_SESSION['astrx_2fa_setup'] ?? null) ? (string) $_SESSION['astrx_2fa_setup'] : '';

        $this->ctx->set('tf_heading',      $this->t->t('twofactor.heading'));
        $this->ctx->set('tf_intro',        $this->t->t('twofactor.intro'));
        $this->ctx->set('tf_enabled',      $enabled);
        $this->ctx->set('status_on',       $this->t->t('twofactor.status_on'));
        $this->ctx->set('status_off',      $this->t->t('twofactor.status_off'));
        $this->ctx->set('confirm_pw_label', $this->t->t('twofactor.confirm_password'));
        $this->ctx->set('btn_begin',       $this->t->t('twofactor.begin'));
        $this->ctx->set('btn_confirm',     $this->t->t('twofactor.confirm'));
        $this->ctx->set('btn_cancel',      $this->t->t('twofactor.cancel'));
        $this->ctx->set('btn_disable',     $this->t->t('twofactor.disable'));
        $this->ctx->set('setup_intro',     $this->t->t('twofactor.setup_intro'));
        $this->ctx->set('secret_label',    $this->t->t('twofactor.secret_label'));
        $this->ctx->set('uri_label',       $this->t->t('twofactor.uri_label'));
        $this->ctx->set('confirm_label',   $this->t->t('twofactor.confirm_label'));
        $this->ctx->set('disable_label',   $this->t->t('twofactor.disable_label'));

        // In-setup (secret minted, not yet confirmed) takes priority over the
        // "off" state so the user sees the key + confirm form.
        $inSetup = !$enabled && $setup !== '';
        $this->ctx->set('tf_setup',   $inSetup);
        $this->ctx->set('tf_can_begin', !$enabled && !$inSetup);
        if ($inSetup) {
            $this->ctx->set('secret',  $setup);
            $this->ctx->set('otpauth', $this->totp->provisioningUri($setup, $this->session->username(), self::ISSUER));
        }

        // Show freshly-minted recovery codes exactly once.
        $once = $_SESSION['astrx_2fa_recovery_once'] ?? null;
        $codes = [];
        if (is_array($once)) {
            foreach ($once as $c) {
                if (is_string($c) && $c !== '') {
                    $codes[] = ['code' => $c];
                }
            }
        }
        $this->ctx->set('show_recovery', $codes !== []);
        $this->ctx->set('recovery_heading', $this->t->t('twofactor.recovery_heading'));
        $this->ctx->set('recovery_intro',   $this->t->t('twofactor.recovery_intro'));
        $this->ctx->set('recovery',         $codes);
        unset($_SESSION['astrx_2fa_recovery_once']);

        $this->ctx->set('prg_id',     $this->prg->createId($selfUrl));
        $this->ctx->set('csrf_token', $this->csrf->generate(self::FORM));

        return $this->ok();
    }

    /** @param array<string,mixed> $posted */
    private function processPost(array $posted, string $uid): void
    {
        $csrfResult = $this->csrf->verify(self::FORM, self::mStr($posted, '_csrf', ''));
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);
            return;
        }

        switch (self::mStr($posted, 'action', '')) {
            case 'begin':
                if ($this->userService->isTotpEnabled($uid)) {
                    return;
                }
                $_SESSION['astrx_2fa_setup'] = $this->totp->generateSecret();
                return;

            case 'cancel':
                unset($_SESSION['astrx_2fa_setup']);
                return;

            case 'confirm':
                $secret = is_string($_SESSION['astrx_2fa_setup'] ?? null) ? (string) $_SESSION['astrx_2fa_setup'] : '';
                if ($secret === '') {
                    return;
                }
                // Re-authenticate: enabling 2FA is a sensitive change, so require the
                // account password (like changePassword) — otherwise a merely-borrowed
                // session could plant an attacker-controlled second factor and lock
                // the owner out durably (a password reset does not clear TOTP).
                if (!$this->userService->verifyPassword($uid, self::mStr($posted, 'password', ''))) {
                    $this->flash->set('error', $this->t->t('twofactor.wrong_password'));
                    return;
                }
                if (!$this->totp->verifyCode($secret, self::mStr($posted, 'code', ''))) {
                    $this->flash->set('error', $this->t->t('twofactor.bad_code'));
                    return;
                }
                $rc = $this->totp->generateRecoveryCodes(10);
                $this->userService->enableTotp($uid, $secret, $rc['hashes'])->drainTo($this->collector);
                unset($_SESSION['astrx_2fa_setup']);
                $_SESSION['astrx_2fa_recovery_once'] = $rc['plain'];
                $this->flash->set('success', $this->t->t('twofactor.enabled'));
                return;

            case 'disable':
                $info = $this->userService->totpInfo($uid);
                if (!$info['enabled']) {
                    return;
                }
                $code = self::mStr($posted, 'code', '');
                $ok = $info['secret'] !== '' && $this->totp->verifyCode($info['secret'], $code);
                if (!$ok && $info['recovery'] !== []) {
                    $ok = $this->totp->verifyRecovery($code, $info['recovery']) !== null;
                }
                if (!$ok) {
                    $this->flash->set('error', $this->t->t('twofactor.bad_code'));
                    return;
                }
                $this->userService->disableTotp($uid)->drainTo($this->collector);
                $this->flash->set('success', $this->t->t('twofactor.disabled'));
                return;
        }
    }
}
