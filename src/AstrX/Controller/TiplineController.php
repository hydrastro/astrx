<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Captcha\CaptchaService;
use AstrX\Config\Config;
use AstrX\Csrf\CsrfHandler;
use AstrX\Http\Request;
use AstrX\Http\Response;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Session\FlashBag;
use AstrX\Session\PrgHandler;
use AstrX\Template\DefaultTemplateContext;
use AstrX\Tipline\TiplineCrypto;
use AstrX\Tipline\TiplineRepository;
use PDO;

/**
 * Public anonymous tip line (/tipline).
 *
 * A visitor types a message; the server SEALS it to the operator's configured
 * X25519 public key with libsodium (crypto_box_seal) and stores ONLY the
 * ciphertext in the `tipline` table — with no IP, session, or user id, so a tip
 * is unlinkable and unreadable there without the offline secret key. Caveat: the
 * submission rides AstrX's site-wide PRG flow, so the cleartext body transits the
 * `session` store for the duration of the redirect (sub-second normally); keep
 * session encryption on (the default) so that copy is also ciphertext at rest.
 * If no valid public key is configured the line is "closed". Captcha-gated
 * (Tipline.captcha, default on) since it is a fully anonymous public write.
 * PRG + CSRF like every other AstrX form.
 */
final class TiplineController extends AbstractController
{
    private const FORM = 'tipline';
    private const MAX_LEN = 16000;

    public function __construct(
        DiagnosticsCollector                    $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request                $request,
        private readonly PDO                    $pdo,
        private readonly Translator             $t,
        private readonly FlashBag               $flash,
        private readonly CsrfHandler            $csrf,
        private readonly PrgHandler             $prg,
        private readonly CaptchaService         $captcha,
        private readonly Config                 $config,
        private readonly TiplineRepository      $repo,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        $this->ctx->set('tip_heading', $this->t->t('tipline.heading'));

        $pubkey = trim($this->cfg('tipline_pubkey'));
        $open   = TiplineCrypto::isValidPubkey($pubkey);
        if (!$open) {
            $this->ctx->set('tip_open', false);
            $this->ctx->set('tip_closed', $this->t->t('tipline.closed'));
            return $this->ok();
        }

        $selfUrl  = $this->request->uri()->path();
        $prgToken = $this->request->query()->get($this->prg->tokenQueryKey());
        if (is_string($prgToken) && $prgToken !== '') {
            $this->processPost($this->prg->pull($prgToken) ?? [], $pubkey);
            Response::redirect($selfUrl)->send()->drainTo($this->collector);
            exit;
        }

        $this->ctx->set('tip_open',    true);
        $this->ctx->set('tip_intro',   $this->t->t('tipline.intro'));
        $this->ctx->set('label_message', $this->t->t('tipline.message'));
        $this->ctx->set('btn_send',    $this->t->t('tipline.send'));
        $this->ctx->set('prg_id',      $this->prg->createId($selfUrl));
        $this->ctx->set('csrf_token',  $this->csrf->generate(self::FORM));
        $this->setCaptcha();

        return $this->ok();
    }

    /**
     * @param array<string,mixed> $posted
     */
    private function processPost(array $posted, string $pubkey): void
    {
        $csrfResult = $this->csrf->verify(self::FORM, self::mStr($posted, '_csrf', ''));
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);
            return;
        }

        if ($this->captchaEnabled()) {
            $captcha = $this->captcha->verify(
                self::mStr($posted, 'captcha_id', ''),
                self::mStr($posted, 'captcha_text', ''),
            );
            if (!$captcha->isOk()) {
                $captcha->drainTo($this->collector);
                $this->flash->set('error', $this->t->t('tipline.bad_captcha'));
                return;
            }
        }

        $message = trim(self::mStr($posted, 'message', ''));
        if ($message === '') {
            $this->flash->set('error', $this->t->t('tipline.empty'));
            return;
        }
        $message = mb_substr($message, 0, self::MAX_LEN);

        $sealed = TiplineCrypto::seal($message, $pubkey);
        // Wipe the plaintext from this process as soon as it is sealed.
        if (function_exists('sodium_memzero')) {
            sodium_memzero($message);
        }
        if ($sealed === null || !$this->repo->store($sealed)) {
            $this->flash->set('error', $this->t->t('tipline.failed'));
            return;
        }

        $this->flash->set('success', $this->t->t('tipline.sent'));
    }

    private function captchaEnabled(): bool
    {
        return $this->config->getConfigBool('Tipline', 'captcha', true);
    }

    private function setCaptcha(): void
    {
        $show = $this->captchaEnabled();
        $this->ctx->set('show_captcha',      $show);
        $this->ctx->set('has_captcha_frame', false);
        $cid = ''; $cimg = '';
        if ($show) {
            $gen = $this->captcha->generate();
            $gen->drainTo($this->collector);
            if ($gen->isOk()) {
                $u    = $gen->unwrap();
                $cid  = $u['id'];
                $cimg = $u['image_b64'];
            }
        }
        $this->ctx->set('captcha_id',    $cid);
        $this->ctx->set('captcha_image', $cimg);
        $this->ctx->set('captcha_label', $this->t->t('tipline.captcha'));
    }

    private function cfg(string $key): string
    {
        try {
            $stmt = $this->pdo->prepare('SELECT `value` FROM `site_config` WHERE `key` = :k LIMIT 1');
            $stmt->execute([':k' => $key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) { return ''; }
            /** @var array<string,mixed> $row */
            return is_scalar($row['value'] ?? null) ? (string) $row['value'] : '';
        } catch (\PDOException) {
            return '';
        }
    }
}
