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

    private bool $enabled       = false;
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
