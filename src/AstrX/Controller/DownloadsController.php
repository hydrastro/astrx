<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Template\DefaultTemplateContext;
use PDO;

/**
 * Public signed-release / download-verification page (/downloads).
 *
 * Shows the operator's release manifest (a list of file → SHA-256) together with
 * an ED25519 signature the SERVER verifies with the operator's configured PUBLIC
 * key (ext-sodium; the public key is safe on the server, the private key never
 * touches it — the operator signs the manifest offline). A visitor sees
 * "signature VALID / INVALID / unsigned" and can independently re-verify the
 * hashes of what they downloaded against the signed list. 404s when nothing is
 * published. Storage: the `site_config` KV (manifest_* keys).
 */
final class DownloadsController extends AbstractController
{
    public function __construct(
        DiagnosticsCollector                    $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly PDO                     $pdo,
        private readonly Translator             $t,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        $this->ctx->set('dl_heading', $this->t->t('downloads.heading'));

        $manifest = trim($this->cfg('manifest_text'));
        if ($manifest === '') {
            http_response_code(404);
            $this->ctx->set('has_manifest', false);
            $this->ctx->set('dl_none', $this->t->t('downloads.none'));
            return $this->ok();
        }

        $pubkey = trim($this->cfg('manifest_pubkey'));
        $sig    = trim($this->cfg('manifest_sig'));
        $status = $this->verifySignature($manifest, $sig, $pubkey);

        $this->ctx->set('has_manifest', true);
        $this->ctx->set('dl_intro',       $this->t->t('downloads.intro'));
        $this->ctx->set('manifest',       $manifest);
        $this->ctx->set('sig_valid',      $status === 'valid');
        $this->ctx->set('sig_invalid',    $status === 'invalid');
        $this->ctx->set('sig_unsigned',   $status === 'unsigned');
        $this->ctx->set('sig_valid_msg',    $this->t->t('downloads.sig_valid'));
        $this->ctx->set('sig_invalid_msg',  $this->t->t('downloads.sig_invalid'));
        $this->ctx->set('sig_unsigned_msg', $this->t->t('downloads.sig_unsigned'));
        $this->ctx->set('dl_pubkey_label', $this->t->t('downloads.pubkey_label'));
        $this->ctx->set('has_pubkey',     $pubkey !== '');
        $this->ctx->set('pubkey',         $pubkey);
        $this->ctx->set('dl_verify_hint', $this->t->t('downloads.verify_hint'));

        return $this->ok();
    }

    /** @return string 'valid' | 'invalid' | 'unsigned' */
    private function verifySignature(string $manifest, string $sigB64, string $pubkeyB64): string
    {
        if ($sigB64 === '' || $pubkeyB64 === '') {
            return 'unsigned';
        }
        if (!extension_loaded('sodium')) {
            return 'unsigned';
        }
        $sig    = base64_decode($sigB64, true);
        $pubkey = base64_decode($pubkeyB64, true);
        if ($sig === false || $pubkey === false
            || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES
            || strlen($pubkey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return 'invalid';
        }
        try {
            return sodium_crypto_sign_verify_detached($sig, $manifest, $pubkey) ? 'valid' : 'invalid';
        } catch (\SodiumException) {
            return 'invalid';
        }
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
