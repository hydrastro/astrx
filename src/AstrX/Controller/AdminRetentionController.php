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
use AstrX\Retention\RetentionService;
use AstrX\Session\FlashBag;
use AstrX\Session\PrgHandler;
use AstrX\Template\DefaultTemplateContext;

/**
 * Data-retention / ephemerality console (/admin-retention).
 *
 * Lists every retention target with its live row count. For age-based targets the
 * operator sets a window (days) and can shred rows past it; for expiry-based chat
 * tables it triggers the existing GC. "Shred all" wipes a target outright, and
 * "Run retention now" applies every configured window in one click (the same
 * thing tools/retention.php does from cron). ADMIN-only (ADMIN_RETENTION).
 */
final class AdminRetentionController extends AbstractController
{
    private const FORM = 'admin_retention';

    public function __construct(
        DiagnosticsCollector                    $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request                $request,
        private readonly Gate                   $gate,
        private readonly Translator             $t,
        private readonly FlashBag               $flash,
        private readonly CsrfHandler            $csrf,
        private readonly PrgHandler             $prg,
        private readonly AuditLogger            $audit,
        private readonly RetentionService       $retention,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        if ($this->gate->cannot(Permission::ADMIN_RETENTION)) {
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

        $rows = [];
        foreach ($this->retention->targets() as $target) {
            $key   = $target['key'];
            $isAge = $target['mode'] === 'age';
            $rows[] = [
                'key'    => $key,
                'label'  => $this->t->t('admin.retention.label.' . $key),
                'is_age' => $isAge,
                'count'  => (string) $this->retention->count($key),
                'window' => $isAge ? (string) $this->retention->window($key) : '',
            ];
        }

        $this->ctx->set('ret_heading',    $this->t->t('admin.retention.heading'));
        $this->ctx->set('ret_intro',      $this->t->t('admin.retention.intro'));
        $this->ctx->set('label_target',   $this->t->t('admin.retention.target'));
        $this->ctx->set('label_rows',     $this->t->t('admin.retention.rows'));
        $this->ctx->set('label_window',   $this->t->t('admin.retention.window'));
        $this->ctx->set('window_hint',    $this->t->t('admin.retention.window_hint'));
        $this->ctx->set('btn_save',       $this->t->t('admin.retention.save'));
        $this->ctx->set('btn_shred',      $this->t->t('admin.retention.shred'));
        $this->ctx->set('btn_gc',         $this->t->t('admin.retention.gc'));
        $this->ctx->set('btn_shred_all',  $this->t->t('admin.retention.shred_all'));
        $this->ctx->set('btn_run_all',    $this->t->t('admin.retention.run_all'));
        $this->ctx->set('btn_reap',       $this->t->t('admin.retention.reap'));
        $this->ctx->set('reap_hint',      $this->t->t('admin.retention.reap_hint'));
        $this->ctx->set('expiry_note',    $this->t->t('admin.retention.expiry_note'));
        $this->ctx->set('targets',        $rows);
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

        $key = self::mStr($posted, 'key', '');

        switch (self::mStr($posted, 'action', '')) {
            case 'save_window':
                $this->retention->setWindow($key, self::mInt($posted, 'window', 0));
                $this->flash->set('success', $this->t->t('admin.retention.saved'));
                $this->audit->log('retention.window', $key)->drainTo($this->collector);
                return;

            case 'shred':
                $n = $this->retention->shred($key);
                $this->flash->set('success', $this->t->t('admin.retention.done') . ' (' . $n . ')');
                $this->audit->log('retention.shred', $key . '×' . $n)->drainTo($this->collector);
                return;

            case 'shred_all':
                $n = $this->retention->purgeAll($key);
                $this->flash->set('success', $this->t->t('admin.retention.done') . ' (' . $n . ')');
                $this->audit->log('retention.shred_all', $key . '×' . $n)->drainTo($this->collector);
                return;

            case 'reap':
                $n = $this->retention->reapOrphanFiles();
                $this->flash->set('success', $this->t->t('admin.retention.done') . ' (' . $n . ')');
                $this->audit->log('retention.reap', 'orphan_files×' . $n)->drainTo($this->collector);
                return;

            case 'run_all':
                $counts = $this->retention->runAll();
                $total  = array_sum($counts);
                $this->flash->set('success', $this->t->t('admin.retention.done') . ' (' . $total . ')');
                $this->audit->log('retention.run_all', 'total×' . $total)->drainTo($this->collector);
                return;
        }
    }
}
