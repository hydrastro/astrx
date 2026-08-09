<?php
declare(strict_types=1);

namespace AstrX\SuiteAdmin;

use AstrX\Config\InjectConfig;

/**
 * Suite admin / status-panel configuration.
 *
 * Bound to the 'SuiteAdminConfig' section of SuiteAdmin.config.php via
 * #[InjectConfig] (the domain resolves to the parent namespace segment
 * 'SuiteAdmin' since there is no SuiteAdminConfig.config.php — the same fallback
 * BotTrapConfig/WebSearchConfig rely on).
 *
 * Holds the four astrx-suite engine base URLs. Each is operator-controlled and
 * MUST point at the localhost engine; each is normalised to an http(s) origin
 * (trailing slash stripped) and any other scheme is rejected to that engine's
 * safe loopback default, so a bad edit can never turn a status probe into an
 * SSRF primitive. SuiteAdminClient only ever appends fixed, code-controlled
 * paths (`/health`, `/healthz`, `/metrics`, `/api/stats`, `/add`) — no
 * user-supplied bytes ever reach these URLs.
 */
final class SuiteAdminConfig
{
    public const string DEFAULT_GITWEB_BASE_URL       = 'http://127.0.0.1:8801';
    public const string DEFAULT_ONIONCRAWLER_BASE_URL = 'http://127.0.0.1:8802';
    public const string DEFAULT_WEBSEARCH_BASE_URL    = 'http://127.0.0.1:8803';
    public const string DEFAULT_TORRENTDS_BASE_URL    = 'http://127.0.0.1:8804';

    /** Hard ceiling on the per-request network timeout, in seconds. */
    public const int MAX_TIMEOUT = 5;

    private string $gitwebBaseUrl       = self::DEFAULT_GITWEB_BASE_URL;
    private string $onioncrawlerBaseUrl = self::DEFAULT_ONIONCRAWLER_BASE_URL;
    private string $websearchBaseUrl    = self::DEFAULT_WEBSEARCH_BASE_URL;
    private string $torrentdsBaseUrl    = self::DEFAULT_TORRENTDS_BASE_URL;
    private int    $timeoutSeconds      = 2;

    #[InjectConfig('gitweb_base_url')]
    public function setGitwebBaseUrl(string $v): void { $this->gitwebBaseUrl = self::normaliseBase($v, self::DEFAULT_GITWEB_BASE_URL); }

    #[InjectConfig('onioncrawler_base_url')]
    public function setOnioncrawlerBaseUrl(string $v): void { $this->onioncrawlerBaseUrl = self::normaliseBase($v, self::DEFAULT_ONIONCRAWLER_BASE_URL); }

    #[InjectConfig('websearch_base_url')]
    public function setWebsearchBaseUrl(string $v): void { $this->websearchBaseUrl = self::normaliseBase($v, self::DEFAULT_WEBSEARCH_BASE_URL); }

    #[InjectConfig('torrentds_base_url')]
    public function setTorrentdsBaseUrl(string $v): void { $this->torrentdsBaseUrl = self::normaliseBase($v, self::DEFAULT_TORRENTDS_BASE_URL); }

    #[InjectConfig('timeout_seconds')]
    public function setTimeoutSeconds(int $v): void { $this->timeoutSeconds = max(1, min(self::MAX_TIMEOUT, $v)); }

    public function gitwebBaseUrl(): string       { return $this->gitwebBaseUrl; }
    public function onioncrawlerBaseUrl(): string { return $this->onioncrawlerBaseUrl; }
    public function websearchBaseUrl(): string    { return $this->websearchBaseUrl; }
    public function torrentdsBaseUrl(): string    { return $this->torrentdsBaseUrl; }
    public function timeoutSeconds(): int         { return $this->timeoutSeconds; }

    /**
     * Force a configured backend to a bare http(s) origin. Rejects any other
     * scheme (and the empty string) back to the given loopback default, so the
     * value can only ever be a localhost-style HTTP endpoint.
     */
    private static function normaliseBase(string $v, string $default): string
    {
        $v = rtrim(trim($v), '/');
        if ($v === '') {
            return $default;
        }
        $scheme = parse_url($v, PHP_URL_SCHEME);
        $scheme = is_string($scheme) ? strtolower($scheme) : '';
        if (!in_array($scheme, ['http', 'https'], true)) {
            return $default;
        }
        return $v;
    }
}
