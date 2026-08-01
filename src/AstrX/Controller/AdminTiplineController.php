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
use AstrX\Tipline\TiplineCrypto;
use AstrX\Tipline\TiplineRepository;
use PDO;

/**
 * Admin surface for the anonymous tip line (/admin-tipline).
 *
 * The operator sets the sealed-box PUBLIC key here (generated offline with
 * tools/tipline.php keygen — the secret half never comes near the server), then
 * sees the queue of sealed tips: id, time, and ciphertext. Reading a tip is done
 * OFFLINE (`php tools/tipline.php decrypt`) so the secret key stays off a box the
 * threat model treats as potentially hostile. Tips can be deleted one-by-one or
 * shredded wholesale. ADMIN-only (ADMIN_TIPLINE). Pubkey lives in `site_config`.
 */
final class AdminTiplineController extends AbstractController
{
    private const FORM = 'admin_tipline';

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
        private readonly TiplineRepository      $repo,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        if ($this->gate->cannot(Permission::ADMIN_TIPLINE)) {
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

        $pubkey = trim($this->cfg('tipline_pubkey'));
        $rows   = $this->repo->recent(200);
        $tips   = [];
        foreach ($rows as $r) {
            $tips[] = [
                'id'         => (string) $r['id'],
                'created_at' => $r['created_at'],
                'sealed'     => $r['sealed'],
            ];
        }

        $this->ctx->set('tip_heading',    $this->t->t('admin.tipline.heading'));
        $this->ctx->set('tip_intro',      $this->t->t('admin.tipline.intro'));
        $this->ctx->set('label_pubkey',   $this->t->t('admin.tipline.pubkey'));
        $this->ctx->set('hint_keygen',    $this->t->t('admin.tipline.hint_keygen'));
        $this->ctx->set('hint_decrypt',   $this->t->t('admin.tipline.hint_decrypt'));
        $this->ctx->set('btn_save',       $this->t->t('admin.tipline.save'));
        $this->ctx->set('btn_delete',     $this->t->t('admin.tipline.delete'));
        $this->ctx->set('btn_purge',      $this->t->t('admin.tipline.purge'));
        $this->ctx->set('queue_heading',  $this->t->t('admin.tipline.queue'));
        $this->ctx->set('label_time',     $this->t->t('admin.tipline.time'));
        $this->ctx->set('label_sealed',   $this->t->t('admin.tipline.sealed'));
        $this->ctx->set('none_yet',       $this->t->t('admin.tipline.none'));
        $this->ctx->set('pubkey',         $pubkey);
        $this->ctx->set('has_pubkey',     $pubkey !== '');
        $this->ctx->set('pubkey_bad',     $pubkey !== '' && !TiplineCrypto::isValidPubkey($pubkey));
        $this->ctx->set('pubkey_bad_msg', $this->t->t('admin.tipline.bad_pubkey'));
        $this->ctx->set('count',          (string) count($tips));
        $this->ctx->set('has_tips',       $tips !== []);
        $this->ctx->set('tips',           $tips);
        $this->ctx->set('prg_id',         $this->prg->createId($selfUrl));
        $this->ctx->set('csrf_token',     $this->csrf->generate(self::FORM));

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

        switch (self::mStr($posted, 'action', '')) {
            case 'save_pubkey':
                $pubkey = trim(self::mStr($posted, 'pubkey', ''));
                if ($pubkey !== '' && !TiplineCrypto::isValidPubkey($pubkey)) {
                    $this->flash->set('error', $this->t->t('admin.tipline.bad_pubkey'));
                    return;
                }
                $this->put('tipline_pubkey', $pubkey);
                $this->flash->set('success', $this->t->t('admin.tipline.saved'));
                $this->audit->log('tipline.pubkey', 'tipline')->drainTo($this->collector);
                return;

            case 'delete':
                $id = self::mInt($posted, 'id', 0);
                if ($id > 0 && $this->repo->delete($id)) {
                    $this->flash->set('success', $this->t->t('admin.tipline.deleted'));
                    $this->audit->log('tipline.delete', 'tipline#' . $id)->drainTo($this->collector);
                }
                return;

            case 'purge':
                $n = $this->repo->purgeAll();
                $this->flash->set('success', $this->t->t('admin.tipline.purged'));
                $this->audit->log('tipline.purge', 'tipline×' . $n)->drainTo($this->collector);
                return;
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
