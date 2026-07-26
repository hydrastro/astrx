<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\BotTrap\BotTrapConfig;
use AstrX\BotTrap\BotTrapLogRepository;
use AstrX\Http\Request;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\Template\DefaultTemplateContext;
use function AstrX\Support\langDir;

/**
 * Bot-trap (honeypot labyrinth) — rendered through the normal site shell
 * (page template=1 → default layout + bot_trap.html content template), so the
 * maze carries the site's active theme, header and navbars and reads like an
 * ordinary section of the site rather than a bare, obviously-synthetic page.
 * That camouflage is deliberate: a stripped document is a tell to a careful
 * crawler; a page that looks like the rest of the site is not.
 *
 * A misbehaving crawler that ignores robots.txt and follows the hidden footer
 * link lands here. Each request emits a FIXED, small number of links to MORE
 * trap URLs (/trap/<random-token>), so the bot wanders an infinite maze while
 * every server request stays O(1) — the maze is infinite for the bot, never a
 * server-side loop. An optional, config-clamped sleep() tarpit wastes the bot's
 * connection time.
 *
 * Bounds (both clamped in BotTrapConfig AND re-clamped here, belt-and-braces):
 *   - tarpit_seconds  → sleep() ≤ MAX_TARPIT_SECONDS, so a hit can never hang.
 *   - links_per_page  → anchors ≤ MAX_LINKS_PER_PAGE, so the page is never
 *     unbounded.
 *
 * Tor-safe: the logged identity is hash('sha256', session_id ?: REMOTE_ADDR) —
 * a raw IP is never stored, and no external request is made. The page's
 * page_robots row emits a noindex/nofollow meta and this controller adds a
 * matching X-Robots-Tag header, so even a co-operative crawler would not index
 * it. Values are HTML-escaped by the template engine.
 *
 * Config-gated (default ON): while disabled, ContentManager swaps this page for
 * the normal themed 404 BEFORE the controller runs (so a disabled /trap is
 * indistinguishable from any missing page); the guard below is defence-in-depth
 * only.
 */
final class BotTrapController extends AbstractController
{
    public function __construct(
        DiagnosticsCollector                    $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request                $request,
        private readonly BotTrapConfig          $config,
        private readonly BotTrapLogRepository   $log,
        private readonly Translator             $t,
        private readonly UrlGenerator           $urlGen,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        // Defence-in-depth: when the trap is disabled, ContentManager already
        // swaps this page for the normal error page BEFORE the controller runs.
        // This guard only fires if that gate is ever bypassed.
        if (!$this->config->enabled()) {
            http_response_code(404);
            exit;
        }

        // Tarpit — clamped to a hard ceiling so a hit can never hang the server.
        $tarpit = max(0, min(BotTrapConfig::MAX_TARPIT_SECONDS, $this->config->tarpitSeconds()));
        if ($tarpit > 0) {
            sleep($tarpit);
        }

        if ($this->config->logHits()) {
            $this->logHit();
        }

        // Keep the trap out of any cooperative crawler's index. The page_robots
        // row already emits the noindex meta in the shell; this header matches
        // it (belt-and-braces). Set before ContentManager renders any output.
        if (!headers_sent()) {
            header('X-Robots-Tag: noindex, nofollow');
        }

        // The maze's visible words come from i18n (no hardcoded strings).
        $this->t->loadDomain(langDir(), 'BotTrap');
        $intro = $this->t->t('bottrap.maze.intro');
        $label = $this->t->t('bottrap.maze.link');

        // Site-relative base for the next maze links (Tor-safe: no host, no
        // external reference). Sub-path tokens keep the bot walking; each hit
        // emits a bounded number of fresh tokens and stays O(1).
        $base  = $this->urlGen->toPage($this->t->t('WORDING_TRAP'));
        $count = max(1, min(BotTrapConfig::MAX_LINKS_PER_PAGE, $this->config->linksPerPage()));

        $links = [];
        for ($i = 1; $i <= $count; $i++) {
            $links[] = [
                'url'   => $base . '/' . bin2hex(random_bytes(8)),
                'label' => $label . ' ' . $i,
            ];
        }

        // Rendered inside the default shell (template=1): same theme + navbars
        // as the rest of the site. The engine HTML-escapes {{url}}/{{label}}.
        $this->ctx->set('heading',    $this->t->t('bottrap.maze.heading'));
        $this->ctx->set('intro',      $intro);
        $this->ctx->set('has_intro',  $intro !== '');
        $this->ctx->set('maze_links', $links);

        return $this->ok();
    }

    /**
     * Record the hit. Tor-safe identity: sha256 of the session id when present,
     * else the REMOTE_ADDR — the raw value is never stored, only its digest.
     */
    private function logHit(): void
    {
        $sid   = session_id();
        $seed  = ($sid !== false && $sid !== '') ? $sid : $this->request->ip();
        $ident = hash('sha256', $seed);

        $path    = self::str($this->request->server()->get('REQUEST_URI'));
        $ua      = $this->request->headers()->get('User-Agent', '') ?? '';
        $referer = $this->request->headers()->get('Referer', '') ?? '';

        $this->log->record($path, $ua, $referer, $ident)->drainTo($this->collector);
    }
}
