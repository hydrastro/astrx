<?php
declare(strict_types=1);

namespace AstrX\BotTrap;

use AstrX\Config\InjectConfig;

/**
 * Central, admin-editable bot-trap (honeypot labyrinth) configuration.
 *
 * Bound to the 'BotTrapConfig' section of BotTrap.config.php via #[InjectConfig]
 * (the domain resolves to the parent namespace segment 'BotTrap' since there is
 * no BotTrapConfig.config.php — the same fallback ImageboardConfig relies on).
 *
 * Default OFF so the operator opts in. Both bounded knobs — the tarpit delay
 * and the links emitted per maze page — are clamped here to hard maxima so a
 * bad edit can never hang the server (tarpit) or emit an unbounded page (links).
 */
final class BotTrapConfig
{
    /** Hard ceiling on the per-hit tarpit delay, in seconds. */
    public const int MAX_TARPIT_SECONDS = 10;

    /** Hard ceiling on the number of maze links emitted per page. */
    public const int MAX_LINKS_PER_PAGE = 20;

    /**
     * How many requests may sit inside the tarpit sleep() at the same time.
     *
     * sleep() on an unauthenticated public URL pins a php-fpm worker for its
     * whole duration. With the shipped tarpit_seconds=1 and a typical pool of
     * 5-10 workers, a dozen concurrent GETs of /trap — which anyone can issue:
     * no session, no captcha, no cost — occupy every worker and the SITE stops
     * answering. The anti-bot lever was a denial-of-service lever pointed at
     * ourselves. BotTrapController claims one of this many lock slots before
     * sleeping and skips the delay when none is free, so the trap still wastes a
     * crawler's time while the pool keeps a floor of free workers.
     *
     * A hard constant, not a config key: AdminTrapController rewrites the
     * BotTrapConfig section whole from a fixed key list, so a new key would be
     * silently dropped the first time an admin saved the bot-trap page.
     */
    public const int MAX_CONCURRENT_TARPITS = 2;

    private bool $enabled       = true;   // matches the shipped BotTrap.config.php + docblock (default ON)
    private int  $tarpitSeconds = 1;
    private int  $linksPerPage  = 5;
    private bool $logHits       = true;

    #[InjectConfig('enabled')]        public function setEnabled(bool $v): void       { $this->enabled = $v; }
    #[InjectConfig('tarpit_seconds')] public function setTarpitSeconds(int $v): void  { $this->tarpitSeconds = max(0, min(self::MAX_TARPIT_SECONDS, $v)); }
    #[InjectConfig('links_per_page')] public function setLinksPerPage(int $v): void   { $this->linksPerPage = max(1, min(self::MAX_LINKS_PER_PAGE, $v)); }
    #[InjectConfig('log_hits')]       public function setLogHits(bool $v): void       { $this->logHits = $v; }

    public function enabled(): bool      { return $this->enabled; }
    public function tarpitSeconds(): int { return $this->tarpitSeconds; }
    public function linksPerPage(): int  { return $this->linksPerPage; }
    public function logHits(): bool      { return $this->logHits; }
}
