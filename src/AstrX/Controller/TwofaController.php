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
 * Second-factor challenge (/login-2fa).
 *
 * Reached only after a password login succeeded for a TOTP-enabled account:
 * LoginController / UserController stash a pending challenge in the session and
 * hand off here WITHOUT granting the session. This page takes a 6-digit TOTP code
 * (or a one-time recovery code) and, only on success, calls session->login() with
 * the stashed user data — so the account is unreachable until the second factor
 * clears. The pending state expires after 10 minutes. No-JS, PRG + CSRF.
 */
final class TwofaController extends AbstractController
{
    private const FORM = 'twofa';
    private const PENDING_TTL = 600;

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
        // Already authenticated → nothing to challenge.
        if ($this->session->isLoggedIn()) {
            Response::redirect($this->urlGen->toPage($this->t->t('WORDING_USER_HOME')))
                ->send()->drainTo($this->collector);
            exit;
        }

        $uid = $this->pendingUid();
        if ($uid === '') {
            // No (or expired) challenge — send them back to the login form.
            $this->clearPending();
            Response::redirect($this->urlGen->toPage($this->t->t('WORDING_LOGIN')))
                ->send()->drainTo($this->collector);
            exit;
        }

        $selfUrl  = $this->request->uri()->path();
        $prgToken = $this->request->query()->get($this->prg->tokenQueryKey());
        if (is_string($prgToken) && $prgToken !== '') {
            $this->processSubmission($this->prg->pull($prgToken) ?? [], $uid);
            // processSubmission redirects+exits on success or lockout. Reaching here
            // is a recoverable failure (bad code / bad CSRF): redirect to the clean
            // URL so a refresh can't replay the consumed token and show a spurious
            // error — the flash carries the message to the settling GET.
            Response::redirect($selfUrl)->send()->drainTo($this->collector);
            exit;
        }

        $this->ctx->set('twofa_heading', $this->t->t('twofa.heading'));
        $this->ctx->set('twofa_intro',   $this->t->t('twofa.intro'));
        $this->ctx->set('label_code',    $this->t->t('twofa.code'));
        $this->ctx->set('twofa_hint',    $this->t->t('twofa.hint'));
        $this->ctx->set('btn_verify',    $this->t->t('twofa.verify'));
        $this->ctx->set('prg_id',        $this->prg->createId($selfUrl));
        $this->ctx->set('csrf_token',    $this->csrf->generate(self::FORM));

        return $this->ok();
    }

    /** @param array<string,mixed> $posted */
    private function processSubmission(array $posted, string $uid): void
    {
        $csrfResult = $this->csrf->verify(self::FORM, self::mStr($posted, '_csrf', ''));
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);
            $this->flash->set('error', $this->t->t('twofa.bad_code'));
            return;
        }

        $code = trim(self::mStr($posted, 'code', ''));
        $info = $this->userService->totpInfo($uid);

        $ok = $info['secret'] !== '' && $this->totp->verifyCode($info['secret'], $code);
        if (!$ok && $info['recovery'] !== []) {
            $idx = $this->totp->verifyRecovery($code, $info['recovery']);
            if ($idx !== null) {
                $ok = true;
                $remaining = $info['recovery'];
                unset($remaining[$idx]);
                // Consume the used recovery code (persist the rest).
                $this->userService->enableTotp($uid, $info['secret'], array_values($remaining))
                    ->drainTo($this->collector);
            }
        }

        if (!$ok) {
            // Throttle online guessing: a wrong code counts against the same
            // brute-force lockout the password step uses. Once it locks, the
            // password step is rejected too, so the attacker can't reopen a fresh
            // challenge window until the cooldown — the second factor is no longer
            // freely guessable even by someone who holds the password.
            if ($this->userService->registerAuthFailure($uid)) {
                $this->clearPending();
                $this->flash->set('error', $this->t->t('twofa.locked'));
                Response::redirect($this->urlGen->toPage($this->t->t('WORDING_LOGIN')))
                    ->send()->drainTo($this->collector);
                exit;
            }
            $this->flash->set('error', $this->t->t('twofa.bad_code'));
            return;
        }

        // Second factor cleared — grant the session now.
        $data = $_SESSION['astrx_pending_2fa_data'] ?? null;
        if (is_array($data)) {
            /** @var array{id:string,username:string,display_name:string,type:int,verified:bool|int,avatar:bool|int,mailbox?:string,theme?:string} $data */
            $this->session->login($data);
        }
        $this->clearPending();

        Response::redirect($this->urlGen->toPage($this->t->t('WORDING_USER_HOME')))
            ->send()->drainTo($this->collector);
        exit;
    }

    /** The pending user id if a valid, unexpired challenge exists; '' otherwise. */
    private function pendingUid(): string
    {
        $uidRaw = $_SESSION['astrx_pending_2fa'] ?? null;
        $tsRaw  = $_SESSION['astrx_pending_2fa_ts'] ?? null;
        $uid = is_string($uidRaw) ? $uidRaw : '';
        $ts  = is_int($tsRaw) ? $tsRaw : (is_numeric($tsRaw) ? (int) $tsRaw : 0);
        if ($uid === '' || $ts <= 0 || (time() - $ts) > self::PENDING_TTL) {
            return '';
        }
        return $uid;
    }

    private function clearPending(): void
    {
        unset(
            $_SESSION['astrx_pending_2fa'],
            $_SESSION['astrx_pending_2fa_data'],
            $_SESSION['astrx_pending_2fa_ts'],
        );
    }
}
