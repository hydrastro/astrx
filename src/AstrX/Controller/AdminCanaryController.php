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
 * Admin editor for the warrant canary (/admin-canary).
 *
 * The operator pastes the (offline-signed) attestation, sets the max age before
 * it is shown as STALE on the public page, and toggles publication. "Save &
 * attest" stamps `canary_updated_at` = now, which is what the public page's
 * stale check measures against — so re-attesting is a single click after the
 * operator re-signs offline. ADMIN-only (ADMIN_CANARY). Stored in `site_config`.
 */
final class AdminCanaryController extends AbstractController
{
    private const FORM = 'admin_canary';

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
        if ($this->gate->cannot(Permission::ADMIN_CANARY)) {
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

        $updatedAt = self::int($this->cfg('canary_updated_at'), 0);

        $this->ctx->set('canary_heading',       $this->t->t('admin.canary.heading'));
        $this->ctx->set('canary_intro',         $this->t->t('admin.canary.intro'));
        $this->ctx->set('label_statement',      $this->t->t('admin.canary.statement'));
        $this->ctx->set('label_interval',       $this->t->t('admin.canary.interval'));
        $this->ctx->set('label_enabled',        $this->t->t('admin.canary.enabled'));
        $this->ctx->set('label_last_attested',  $this->t->t('admin.canary.last_attested'));
        $this->ctx->set('btn_save',             $this->t->t('admin.canary.save'));
        $this->ctx->set('btn_clear',            $this->t->t('admin.btn.clear'));
        $this->ctx->set('statement',            $this->cfg('canary_statement'));
        $this->ctx->set('interval_days',        (string) max(1, self::int($this->cfg('canary_interval_days'), 14)));
        $this->ctx->set('enabled_checked',      $this->cfg('canary_enabled') === '1');
        $this->ctx->set('last_attested',        $updatedAt > 0 ? gmdate('Y-m-d H:i', $updatedAt) . ' UTC' : '—');
        $this->ctx->set('prg_id',               $this->prg->createId($selfUrl));
        $this->ctx->set('csrf_token',           $this->csrf->generate(self::FORM));

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
            $this->put('canary_statement', '');
            $this->put('canary_enabled', '0');
            $this->flash->set('success', $this->t->t('admin.canary.cleared'));
            $this->audit->log('canary.clear', 'warrant_canary')->drainTo($this->collector);
            return;
        }
        if ($action !== 'save') {
            return;
        }

        // Clamp the stale interval to a sane 1..365 days.
        $interval = max(1, min(365, self::mInt($posted, 'interval_days', 14)));
        $this->put('canary_statement',     self::mStr($posted, 'statement', ''));
        $this->put('canary_interval_days', (string) $interval);
        $this->put('canary_enabled',       self::mBool($posted, 'enabled') ? '1' : '0');
        // "Save & attest" — stamp the attestation time the public stale check measures.
        $this->put('canary_updated_at',    (string) time());

        $this->flash->set('success', $this->t->t('admin.canary.saved'));
        $this->audit->log('canary.save', 'warrant_canary')->drainTo($this->collector);
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
            // Non-fatal — this key won't persist this request.
        }
    }
}
