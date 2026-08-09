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
use AstrX\SuiteAdmin\SuiteAdminClient;
use AstrX\Template\DefaultTemplateContext;
use function AstrX\Support\langDir;

/**
 * Suite status / control panel (/admin-suite).
 *
 * A read-mostly AstrX ADMIN page that surfaces the health + a few key metrics of
 * the four standalone astrx-suite engines (gitweb, onioncrawler, websearch,
 * torrentds), and exposes the ONE control action any of them offers: submitting
 * an onion seed to onioncrawler's `GET/POST /add`. Every engine has NO other
 * write endpoint, so the rest of the panel is deliberately display-only (see the
 * README). ADMIN-only, gated with the same Permission::ADMIN_ACCESS the admin
 * section root uses.
 *
 * Seeded with file_name 'admin_suite' as a child of the admin root, so the
 * reflection router resolves it to THIS class and the template resolves to
 * resources/template/admin/admin_suite.html. All HTTP to the engines, tolerant
 * Prometheus/JSON parsing and sanitisation happen in SuiteAdminClient; this
 * controller only gates, translates, runs the CSRF-protected PRG seed form and
 * shapes the view model. A down backend can never 500 the page — the client
 * degrades every failure to a friendly DOWN row.
 */
final class AdminSuiteController extends AbstractController
{
    private const FORM = 'admin_suite';

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
        private readonly SuiteAdminClient       $client,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        $this->t->loadDomain(langDir(), 'SuiteAdmin');

        if ($this->gate->cannot(Permission::ADMIN_ACCESS)) {
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

        // ── Status panel (live probe of all four engines) ──────────────────────
        $engines = [];
        foreach ($this->client->statuses() as $e) {
            $engines[] = [
                'name'         => $e['name'],
                'label'        => $e['label'],
                'up'           => $e['up'],
                'status_label' => $e['up'] ? $this->t->t('suiteadmin.up') : $this->t->t('suiteadmin.down'),
                'latency'      => $e['latency_ms'] !== null ? (string) $e['latency_ms'] . ' ms' : '—',
                'health_path'  => $e['health_path'] !== '' ? $e['health_path'] : '—',
                'error'        => $e['error'],
                'has_error'    => $e['error'] !== '',
                'metrics'      => $e['metrics'],
                'display_only' => $e['control'] === '',
                'control_note' => $e['control'] === 'onion_seed'
                    ? $this->t->t('suiteadmin.control.onion_seed')
                    : $this->t->t('suiteadmin.control.none'),
            ];
        }
        $this->ctx->set('engines', $engines);

        $this->setLabels();
        $this->ctx->set('prg_id',     $this->prg->createId($selfUrl));
        $this->ctx->set('csrf_token', $this->csrf->generate(self::FORM));

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

        if (self::mStr($posted, 'action', '') !== 'add_seed') {
            return;
        }

        $seed = self::mNullableTrimmed($posted, 'onion_seed');
        if ($seed === null) {
            $this->flash->set('error', $this->t->t('suiteadmin.seed.empty'));
            return;
        }

        $res = $this->client->submitOnionSeed($seed);
        // Map the engine's outcome to a translated flash + a 'success'|'notice'|
        // 'error' type. Never echo the untrusted seed back into the message.
        [$type, $key] = match ($res['status']) {
            'queued'    => ['success', 'suiteadmin.seed.queued'],
            'duplicate' => ['notice',  'suiteadmin.seed.duplicate'],
            'blocked'   => ['error',   'suiteadmin.seed.blocked'],
            'invalid'   => ['error',   'suiteadmin.seed.invalid'],
            'forbidden' => ['error',   'suiteadmin.seed.forbidden'],
            'empty'     => ['error',   'suiteadmin.seed.empty'],
            'unreachable' => ['error', 'suiteadmin.seed.unreachable'],
            default     => ['error',   'suiteadmin.seed.error'],
        };
        $this->flash->set($type, $this->t->t($key));
        $this->audit->log('suite.onion_seed', 'onioncrawler', $res['status'])
            ->drainTo($this->collector);
    }

    private function setLabels(): void
    {
        foreach ([
            'suite_heading'   => 'suiteadmin.heading',
            'suite_intro'     => 'suiteadmin.intro',
            'col_engine'      => 'suiteadmin.col.engine',
            'col_status'      => 'suiteadmin.col.status',
            'col_latency'     => 'suiteadmin.col.latency',
            'col_health'      => 'suiteadmin.col.health',
            'col_metrics'     => 'suiteadmin.col.metrics',
            'col_control'     => 'suiteadmin.col.control',
            'seed_heading'    => 'suiteadmin.seed.heading',
            'seed_intro'      => 'suiteadmin.seed.intro',
            'seed_label'      => 'suiteadmin.seed.label',
            'seed_submit'     => 'suiteadmin.seed.submit',
        ] as $ctxKey => $tKey) {
            $this->ctx->set($ctxKey, $this->t->t($tKey));
        }
    }
}
