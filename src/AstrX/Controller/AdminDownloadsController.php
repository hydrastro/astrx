<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Admin\AuditLogger;
use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Csrf\CsrfHandler;
use AstrX\Http\Request;
use AstrX\Http\Response;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Session\FlashBag;
use AstrX\Session\PrgHandler;
use AstrX\Template\DefaultTemplateContext;
use PDO;

/**
 * Admin editor for the signed release manifest (/admin-downloads).
 *
 * The operator pastes a release manifest (a list of file → SHA-256), the ED25519
 * PUBLIC key, and the detached signature — all produced offline, the private key
 * never touching the server. The public /downloads page then verifies the
 * signature server-side and shows VALID / INVALID / unsigned. Pubkey and sig are
 * validated here as base64 of the exact ED25519 sizes so a paste error is caught
 * at save time, not silently rendered as "invalid" to every visitor. ADMIN-only
 * (ADMIN_DOWNLOADS). Stored in the `site_config` KV (manifest_* keys).
 */
final class AdminDownloadsController extends AbstractController
{
    private const FORM = 'admin_downloads';

    public function __construct(
        DiagnosticsCollector                    $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request                $request,
        private readonly PDO                    $pdo,
        private readonly Gate                   $gate,
        private readonly Translator             $t,
        private readonly FlashBag               $flash,
        private readonly CsrfHandler            $csrf,
        private readonly PrgHandler             $prg,
        private readonly AuditLogger            $audit,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        if ($this->gate->cannot(Permission::ADMIN_DOWNLOADS)) {
            http_response_code(403);
            $this->ctx->set('admin_forbidden', true);
            $this->ctx->set('forbidden_message', $this->t->t('admin.forbidden'));
            return $this->ok();
        }

        $selfUrl  = $this->request->uri()->path();
        $prgToken = $this->request->query()->get($this->prg->tokenQueryKey());
        if (is_string($prgToken) && $prgToken !== '') {
            $this->processPost($this->prg->pull($prgToken) ?? []);
            Response::redirect($selfUrl)->send()->drainTo($this->collector);
            exit;
        }

        $this->ctx->set('dl_heading',    $this->t->t('admin.downloads.heading'));
        $this->ctx->set('dl_intro',      $this->t->t('admin.downloads.intro'));
        $this->ctx->set('label_manifest', $this->t->t('admin.downloads.manifest'));
        $this->ctx->set('label_pubkey',  $this->t->t('admin.downloads.pubkey'));
        $this->ctx->set('label_sig',     $this->t->t('admin.downloads.sig'));
        $this->ctx->set('hint_sign',     $this->t->t('admin.downloads.hint_sign'));
        $this->ctx->set('btn_save',      $this->t->t('admin.downloads.save'));
        $this->ctx->set('btn_clear',     $this->t->t('admin.btn.clear'));
        $this->ctx->set('manifest',      $this->cfg('manifest_text'));
        $this->ctx->set('pubkey',        $this->cfg('manifest_pubkey'));
        $this->ctx->set('sig',           $this->cfg('manifest_sig'));
        $this->ctx->set('prg_id',        $this->prg->createId($selfUrl));
        $this->ctx->set('csrf_token',    $this->csrf->generate(self::FORM));

        return $this->ok();
    }

    /** @param array<string,mixed> $posted */
    private function processPost(array $posted): void
    {
        $csrfResult = $this->csrf->verify(self::FORM, self::mStr($posted, '_csrf', ''));
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);
            return;
        }

        $action = self::mStr($posted, 'action', '');
        if ($action === 'clear') {
            $this->put('manifest_text', '');
            $this->put('manifest_pubkey', '');
            $this->put('manifest_sig', '');
            $this->flash->set('success', $this->t->t('admin.downloads.cleared'));
            $this->audit->log('downloads.clear', 'release_manifest')->drainTo($this->collector);
            return;
        }
        if ($action !== 'save') {
            return;
        }

        $pubkey = trim(self::mStr($posted, 'pubkey', ''));
        $sig    = trim(self::mStr($posted, 'sig', ''));

        // Reject a malformed key / signature at save time rather than shipping a
        // permanent "INVALID" to every visitor. Empty is allowed (an unsigned
        // manifest just displays without a verification badge). The ED25519 sizes
        // are fixed (32-byte key, 64-byte sig); enforce them only when ext-sodium
        // is present — without it the public page cannot verify anyway.
        if (extension_loaded('sodium')) {
            if ($pubkey !== '' && !self::isB64OfLen($pubkey, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)) {
                $this->flash->set('error', $this->t->t('admin.downloads.bad_pubkey'));
                return;
            }
            if ($sig !== '' && !self::isB64OfLen($sig, SODIUM_CRYPTO_SIGN_BYTES)) {
                $this->flash->set('error', $this->t->t('admin.downloads.bad_sig'));
                return;
            }
        }

        // Normalise the textarea's CRLF newlines to LF before storing: the operator
        // signs the LF form offline (every unix tool emits LF), but browsers submit
        // <textarea> content with CRLF — verifying the raw CRLF bytes against an
        // LF signature would fail every correctly-signed manifest.
        $manifest = str_replace("\r\n", "\n", self::mStr($posted, 'manifest', ''));
        $this->put('manifest_text',   mb_substr($manifest, 0, 20000));
        $this->put('manifest_pubkey', $pubkey);
        $this->put('manifest_sig',    $sig);

        $this->flash->set('success', $this->t->t('admin.downloads.saved'));
        $this->audit->log('downloads.save', 'release_manifest')->drainTo($this->collector);
    }

    /** True when $b64 is strict base64 decoding to exactly $len bytes. */
    private static function isB64OfLen(string $b64, int $len): bool
    {
        $raw = base64_decode($b64, true);
        return $raw !== false && strlen($raw) === $len;
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

    private function put(string $key, string $value): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO `site_config` (`key`, `value`) VALUES (:k, :v)
                 ON DUPLICATE KEY UPDATE `value` = :v2'
            );
            $stmt->execute([':k' => $key, ':v' => $value, ':v2' => $value]);
        } catch (\PDOException) {
            // Non-fatal.
        }
    }
}
