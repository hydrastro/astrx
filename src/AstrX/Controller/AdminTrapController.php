<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\BotTrap\BotTrapConfig;
use AstrX\BotTrap\BotTrapLogRepository;
use AstrX\Config\ConfigWriter;
use AstrX\Csrf\CsrfHandler;
use AstrX\Http\Request;
use AstrX\Http\Response;
use AstrX\I18n\Translator;
use AstrX\Page\Page;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\Session\FlashBag;
use AstrX\Session\PrgHandler;
use AstrX\Template\DefaultTemplateContext;
use function AstrX\Support\langDir;

/**
 * Admin — Bot-trap control panel + log viewer.
 *
 * Two surfaces on one page: a ConfigWriter-backed settings form that toggles the
 * honeypot labyrinth and tunes its two bounded knobs (tarpit delay, links per
 * maze page) plus hit-logging, and a read-only display of the most recent
 * `bot_trap_log` hits (newest first) so the operator can see who ignored
 * robots.txt and got lured in. Every displayed identity is the stored sha256
 * digest (further shortened for the column) — no raw IP exists to show.
 *
 * The form mirrors the other admin config editors: gate on ADMIN_ACCESS, PRG+CSRF
 * on submit, then a self-redirect; on GET the fields are populated from the
 * current {@see BotTrapConfig}. The single 'BotTrapConfig' section of
 * BotTrap.config.php is rewritten whole through {@see ConfigWriter}. The knobs
 * are clamped here to the same hard maxima BotTrapConfig enforces, so a bad edit
 * can never hang the server (tarpit) or emit an unbounded page (links).
 */
final class AdminTrapController extends AbstractController
{
    /** How many recent hits the viewer lists. */
    private const int RECENT_LIMIT = 100;

    private const string FORM = 'admin_trap';

    public function __construct(
        DiagnosticsCollector                    $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request                $request,
        private readonly Gate                   $gate,
        private readonly Translator             $t,
        private readonly BotTrapConfig          $config,
        private readonly BotTrapLogRepository   $log,
        private readonly ConfigWriter           $writer,
        private readonly CsrfHandler            $csrf,
        private readonly PrgHandler             $prg,
        private readonly FlashBag               $flash,
        private readonly Page                   $page,
        private readonly UrlGenerator           $urlGen,
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

        $this->t->loadDomain(langDir(), 'BotTrap');

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

        // Rewrites BotTrap.config.php's single 'BotTrapConfig' section whole.
        // Both knobs are clamped to the same hard bounds BotTrapConfig enforces;
        // unchecked checkboxes are simply absent from POST, so mBool yields false
        // — that is exactly how the enable/disable + logging toggles work.
        $section = [
            'enabled'        => self::mBool($posted, 'enabled'),
            'tarpit_seconds' => max(0, min(BotTrapConfig::MAX_TARPIT_SECONDS, self::mInt($posted, 'tarpit_seconds', 1))),
            'links_per_page' => max(1, min(BotTrapConfig::MAX_LINKS_PER_PAGE, self::mInt($posted, 'links_per_page', 5))),
            'log_hits'       => self::mBool($posted, 'log_hits'),
        ];

        $result = $this->writer->write('BotTrap', ['BotTrapConfig' => $section]);
        $result->drainTo($this->collector);
        if ($result->isOk()) {
            $this->flash->set('success', $this->t->t('admin.config.saved'));
        }
    }

    // =========================================================================
    // GET rendering
    // =========================================================================

    private function buildContext(string $selfUrl): void
    {
        $r    = $this->log->recent(self::RECENT_LIMIT)->drainTo($this->collector);
        $rows = $r->isOk() ? $r->unwrap() : [];

        $view = [];
        foreach ($rows as $row) {
            $view[] = [
                'created_at' => self::mStr($row, 'created_at'),
                // Shorten the digest for display — it is already a one-way hash.
                'ident'      => mb_substr(self::mStr($row, 'ident'), 0, 16),
                'path'       => self::mStr($row, 'path'),
                'user_agent' => self::mStr($row, 'user_agent'),
                'referer'    => self::mStr($row, 'referer'),
            ];
        }

        $this->ctx->set('trap_rows',   $view);
        $this->ctx->set('has_rows',    $view !== []);
        $this->ctx->set('row_count',   count($view));

        // Current config → read-only status table.
        $this->ctx->set('cfg_enabled', $this->config->enabled());
        $this->ctx->set('cfg_tarpit',  $this->config->tarpitSeconds());
        $this->ctx->set('cfg_links',   $this->config->linksPerPage());
        $this->ctx->set('cfg_logging', $this->config->logHits());

        // Current config → editable form (PRG+CSRF) field values.
        $this->ctx->set('csrf_token', $this->csrf->generate(self::FORM));
        $this->ctx->set('prg_id',     $this->prg->createId($selfUrl));
        $this->ctx->set('f_enabled',  $this->config->enabled());
        $this->ctx->set('f_tarpit',   $this->config->tarpitSeconds());
        $this->ctx->set('f_links',    $this->config->linksPerPage());
        $this->ctx->set('f_logging',  $this->config->logHits());

        $this->setI18n();
    }

    private function setI18n(): void
    {
        foreach ([
            'heading'          => 'bottrap.admin.heading',
            'intro'            => 'bottrap.admin.intro',
            'settings_heading' => 'bottrap.admin.settings_heading',
            'settings_hint'    => 'bottrap.admin.settings_hint',
            'lbl_enabled'      => 'bottrap.admin.enabled',
            'lbl_tarpit'       => 'bottrap.admin.tarpit',
            'lbl_links'        => 'bottrap.admin.links',
            'lbl_logging'      => 'bottrap.admin.logging',
            'btn_save'         => 'bottrap.admin.save',
            'lbl_yes'          => 'bottrap.admin.yes',
            'lbl_no'           => 'bottrap.admin.no',
            'lbl_time'         => 'bottrap.admin.time',
            'lbl_ident'        => 'bottrap.admin.ident',
            'lbl_path'         => 'bottrap.admin.path',
            'lbl_ua'           => 'bottrap.admin.ua',
            'lbl_referer'      => 'bottrap.admin.referer',
            'lbl_count'        => 'bottrap.admin.count',
            'lbl_none'         => 'bottrap.admin.none',
        ] as $ctxKey => $tKey) {
            $this->ctx->set($ctxKey, $this->t->t($tKey));
        }
    }
}
