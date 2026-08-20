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

        // Tarpit — clamped to a hard ceiling AND bounded in CONCURRENCY, so a
        // hit can neither hang a worker for long nor occupy all of them.
        $tarpit = max(0, min(BotTrapConfig::MAX_TARPIT_SECONDS, $this->config->tarpitSeconds()));
        if ($tarpit > 0) {
            $this->tarpitWithinConcurrencyBound($tarpit);
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
     * sleep($seconds), but only while fewer than
     * BotTrapConfig::MAX_CONCURRENT_TARPITS requests are already sleeping.
     *
     * Why this exists: /trap is public, unauthenticated and free to request, and
     * a bare sleep() pins one php-fpm worker per hit. On a pool of, say, eight
     * workers, eight simultaneous GETs of /trap with tarpit_seconds=10 take the
     * entire site offline for ten seconds — repeatable indefinitely by one
     * client with a for-loop. The trap is supposed to cost the CRAWLER time.
     *
     * The bound is a set of lock files claimed with flock(LOCK_EX|LOCK_NB). No
     * free slot means no sleep: the bot still gets its maze page, just without
     * the delay, which is the correct thing to give up under load. Locks are
     * released by fclose() — and by the kernel if the worker dies mid-sleep, so
     * a crashed request cannot leak a slot permanently the way a counter file
     * would.
     */
    private function tarpitWithinConcurrencyBound(int $seconds): void
    {
        $dir = sys_get_temp_dir();

        for ($slot = 0; $slot < BotTrapConfig::MAX_CONCURRENT_TARPITS; $slot++) {
            $path = $dir . DIRECTORY_SEPARATOR . 'astrx_bottrap_tarpit_' . $slot . '.lock';

            // A local user could park a symlink at the predictable path; we only
            // ever lock these files, never write through them, but skipping a
            // symlink keeps us from touching anything we did not create.
            if (is_link($path)) {
                continue;
            }

            // 'c' = create if missing, do not truncate: the file is a lock
            // token, its contents are irrelevant and never read.
            $handle = @fopen($path, 'c');
            if ($handle === false) {
                continue;
            }

            if (@flock($handle, LOCK_EX | LOCK_NB)) {
                sleep($seconds);
                @flock($handle, LOCK_UN);
                fclose($handle);
                return;
            }

            fclose($handle);
        }

        // Every slot busy → serve the maze immediately. Shedding the delay is
        // the whole point of the bound.
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
