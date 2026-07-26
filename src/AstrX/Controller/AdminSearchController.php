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
use AstrX\Page\Page;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\Search\SearchIndexer;
use AstrX\Session\FlashBag;
use AstrX\Session\PrgHandler;
use AstrX\Template\DefaultTemplateContext;
use function AstrX\Support\indexDir;

/**
 * Admin — Search index control panel.
 *
 * Read/act surface for the on-demand search crawler ({@see SearchIndexer}).
 * On GET it shows the current job status, indexed document count, last run and
 * whether the index is stale (newer content exists than the last crawl). Two
 * no-JavaScript PRG-POST buttons drive it:
 *
 *   rebuild_now      — if exec() is available, spawn tools/search_index.php as a
 *                      detached background process and flag the job running;
 *                      otherwise fall back to queueing a request for cron/CLI.
 *   request_rebuild  — queue a rebuild for the next cron / manual CLI run.
 *
 * The page also prints the exact CLI and cron commands so an operator can wire
 * the crawler up outside the web UI.
 *
 * SECURITY: the background command is built ONLY from PHP_BINARY and the
 * server-side INDEX_DIR constant — never from any request/user input — so there
 * is no shell-injection surface. Gated on ADMIN_ACCESS.
 */
final class AdminSearchController extends AbstractController
{
    private const string FORM = 'admin_search';

    /** Relative path (under INDEX_DIR) of the crawler CLI entry point. */
    private const string CLI_SCRIPT = 'tools/search_index.php';

    /**
     * A 'running' job older than this (seconds) is treated as likely wedged, so
     * the page flags it stale and nudges the operator toward the reset button.
     */
    private const int STALE_RUNNING_SECONDS = 600;

    public function __construct(
        DiagnosticsCollector                    $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request                $request,
        private readonly Gate                   $gate,
        private readonly Translator             $t,
        private readonly UrlGenerator           $urlGen,
        private readonly CsrfHandler            $csrf,
        private readonly PrgHandler             $prg,
        private readonly FlashBag               $flash,
        private readonly Page                   $page,
        private readonly AuditLogger            $audit,
        private readonly SearchIndexer          $indexer,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        if ($this->gate->cannot(Permission::ADMIN_ACCESS)) {
            http_response_code(403);
            $this->ctx->set('admin_forbidden', true);
            $this->ctx->set('forbidden_message', $this->t->t('admin.forbidden'));
            return $this->ok();
        }

        $resolvedUrlId = $this->page->i18n
            ? $this->t->t($this->page->urlId, fallback: $this->page->urlId)
            : $this->page->urlId;
        $selfUrl = $this->urlGen->toPage($resolvedUrlId);

        $prgToken = $this->request->query()->get($this->prg->tokenQueryKey());
        if (is_string($prgToken) && $prgToken !== '') {
            $this->processForm($prgToken);
            Response::redirect($selfUrl)->send()->drainTo($this->collector);
            exit;
        }

        $this->buildContext($selfUrl);
        return $this->ok();
    }

    // =========================================================================
    // PRG POST handling
    // =========================================================================

    private function processForm(string $prgToken): void
    {
        $posted     = $this->prg->pull($prgToken) ?? [];
        $csrfResult = $this->csrf->verify(self::FORM, self::mStr($posted, '_csrf', ''));
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);
            return;
        }

        match (self::mStr($posted, 'action', '')) {
            'rebuild_now'     => $this->rebuildNow(),
            'request_rebuild' => $this->queueRebuild(),
            'reset_job'       => $this->resetJob(),
            default           => null,
        };
    }

    /**
     * Force a wedged job row back to idle so the operator can retry a rebuild.
     * The Rebuild/Queue buttons self-suppress while 'running', so a job stuck in
     * that state (dead background child, php-fpm binary spawned, etc.) can only
     * be cleared here.
     */
    private function resetJob(): void
    {
        $this->indexer->resetJob()->drainTo($this->collector);
        $this->flash->set('success', $this->t->t('admin.search.flash.reset'));
        $this->audit->log('search.index.reset', 'search_index')->drainTo($this->collector);
    }

    /**
     * Start a rebuild immediately. Prefers a detached background process; if
     * exec() is unavailable (disabled_functions / non-CLI host), degrades to
     * queueing the job for cron/CLI so the button always does something useful.
     */
    private function rebuildNow(): void
    {
        if ($this->execAvailable()) {
            $this->spawnBackgroundRebuild();
            $this->indexer->markRunning();
            $this->flash->set('success', $this->t->t('admin.search.flash.started'));
            $this->audit->log('search.index.rebuild', 'search_index', 'background')
                ->drainTo($this->collector);
            return;
        }

        $this->indexer->requestRebuild()->drainTo($this->collector);
        $this->flash->set('warning', $this->t->t('admin.search.flash.queued'));
        $this->audit->log('search.index.request', 'search_index', 'exec-unavailable')
            ->drainTo($this->collector);
    }

    /** Queue a rebuild for the next cron tick / manual CLI run. */
    private function queueRebuild(): void
    {
        $this->indexer->requestRebuild()->drainTo($this->collector);
        $this->flash->set('success', $this->t->t('admin.search.flash.requested'));
        $this->audit->log('search.index.request', 'search_index')->drainTo($this->collector);
    }

    // =========================================================================
    // GET rendering
    // =========================================================================

    private function buildContext(string $selfUrl): void
    {
        $status = $this->indexer->status();

        $this->ctx->set('csrf_token', $this->csrf->generate(self::FORM));
        $this->ctx->set('prg_id',     $this->prg->createId($selfUrl));

        $this->ctx->set('job_status',   $this->statusLabel($status['status']));
        $this->ctx->set('is_running',   $status['status'] === 'running');

        // Stale-running: a job that has claimed 'running' for far longer than any
        // real crawl should take is almost certainly wedged. time() is fine here
        // (plain web request); started_at comes from the DB clock via status().
        $isStaleRunning = $status['status'] === 'running'
            && $status['started_at'] > 0
            && (time() - $status['started_at']) > self::STALE_RUNNING_SECONDS;
        $this->ctx->set('is_stale_running', $isStaleRunning);
        $this->ctx->set('doc_count',    $status['live_count']);
        $this->ctx->set('last_run',     $this->formatTs($status['finished_at']));
        $this->ctx->set('last_message', $status['message']);
        $this->ctx->set('has_message',  $status['message'] !== '');
        $this->ctx->set('is_stale',     $status['stale']);
        $this->ctx->set('index_time',   $this->formatTs($status['indexed_at']));

        $execAvailable = $this->execAvailable();
        $this->ctx->set('exec_available', $execAvailable);

        // Informational commands (human-readable; the actual exec() call escapes
        // its arguments — see spawnBackgroundRebuild()). Uses the CLI php binary,
        // NOT PHP_BINARY (which under php-fpm is the FPM daemon and can't run a
        // CLI script).
        $script = indexDir() . self::CLI_SCRIPT;
        $php    = $this->phpCliBinary();
        $this->ctx->set('cli_command',  $php . ' ' . $script);
        $this->ctx->set('cron_command', '*/15 * * * * ' . $php . ' ' . $script . ' --if-requested');

        $this->setI18n();
    }

    private function setI18n(): void
    {
        $this->ctx->set('heading', $this->t->t('admin.search.heading'));
        $this->ctx->set('intro',   $this->t->t('admin.search.intro'));

        $this->ctx->set('lbl_status',       $this->t->t('admin.search.status'));
        $this->ctx->set('lbl_doc_count',    $this->t->t('admin.search.doc_count'));
        $this->ctx->set('lbl_last_run',     $this->t->t('admin.search.last_run'));
        $this->ctx->set('lbl_last_message', $this->t->t('admin.search.last_message'));
        $this->ctx->set('lbl_index_time',   $this->t->t('admin.search.index_time'));
        $this->ctx->set('lbl_freshness',    $this->t->t('admin.search.freshness'));
        $this->ctx->set('txt_stale',        $this->t->t('admin.search.stale'));
        $this->ctx->set('txt_fresh',        $this->t->t('admin.search.fresh'));

        $this->ctx->set('btn_rebuild',  $this->t->t('admin.search.btn_rebuild'));
        $this->ctx->set('btn_request',  $this->t->t('admin.search.btn_request'));
        $this->ctx->set('btn_reset',    $this->t->t('admin.search.btn_reset'));
        $this->ctx->set('hint_rebuild', $this->t->t('admin.search.hint_rebuild'));
        $this->ctx->set('hint_request', $this->t->t('admin.search.hint_request'));
        $this->ctx->set('hint_exec',    $this->t->t('admin.search.hint_exec'));
        $this->ctx->set('txt_stale_running', $this->t->t('admin.search.stale_running'));

        $this->ctx->set('cli_heading', $this->t->t('admin.search.cli_heading'));
        $this->ctx->set('cli_intro',   $this->t->t('admin.search.cli_intro'));
        $this->ctx->set('cli_note',    $this->t->t('admin.search.cli_note'));
        $this->ctx->set('cron_heading', $this->t->t('admin.search.cron_heading'));
        $this->ctx->set('cron_intro',   $this->t->t('admin.search.cron_intro'));
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'running'   => $this->t->t('admin.search.state_running'),
            'requested' => $this->t->t('admin.search.state_requested'),
            default     => $this->t->t('admin.search.state_idle'),
        };
    }

    private function formatTs(int $ts): string
    {
        return $ts > 0 ? date('Y-m-d H:i:s', $ts) : $this->t->t('admin.search.never');
    }

    /** True when exec() exists and is not listed in disable_functions. */
    private function execAvailable(): bool
    {
        if (!function_exists('exec')) {
            return false;
        }
        $disabled = ini_get('disable_functions');
        if (!is_string($disabled) || $disabled === '') {
            return true;
        }
        $list = array_map('trim', explode(',', strtolower($disabled)));
        return !in_array('exec', $list, true);
    }

    /**
     * Spawn the crawler as a detached background process.
     *
     * SECURITY: the command is assembled solely from the resolved CLI php binary
     * and the server-side INDEX_DIR constant, both escaped with escapeshellarg().
     * No request/user input is ever interpolated into the command line.
     */
    private function spawnBackgroundRebuild(): void
    {
        $script  = indexDir() . self::CLI_SCRIPT;
        $command = escapeshellarg($this->phpCliBinary()) . ' ' . escapeshellarg($script) . ' > /dev/null 2>&1 &';
        exec($command);
    }

    /**
     * A CLI php binary usable for the background spawn and the shown commands.
     *
     * PHP_BINARY is the running SAPI's binary — under php-fpm that is the FPM
     * daemon (e.g. /usr/local/sbin/php-fpm), which CANNOT run a CLI script: it
     * prints its usage and exits, so the crawl never starts and the job is left
     * wedged in 'running'. Prefer the `php` CLI that normally sits in PHP_BINDIR
     * beside it; only reuse PHP_BINARY when it is itself a CLI php; otherwise
     * fall back to `php` on PATH.
     */
    private function phpCliBinary(): string
    {
        // PHP_BINARY is only usable directly when it is a CLI php (not the FPM
        // daemon). PHP_BINARY and PHP_BINDIR are predefined non-empty constants.
        if (!str_contains(strtolower(PHP_BINARY), 'fpm')) {
            return PHP_BINARY;
        }
        $candidate = PHP_BINDIR . DIRECTORY_SEPARATOR . 'php';
        if (@is_executable($candidate)) {
            return $candidate;
        }
        return 'php';
    }
}
