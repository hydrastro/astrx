<?php
declare(strict_types=1);

namespace AstrX\FederatedSearch;

use AstrX\Config\InjectConfig;

/**
 * Unified (federated) search backend configuration.
 *
 * Bound to the 'FederatedSearchConfig' section of FederatedSearch.config.php via
 * #[InjectConfig] (the domain resolves to the parent namespace segment
 * 'FederatedSearch' since there is no FederatedSearchConfig.config.php — the same
 * fallback BotTrapConfig/WebSearchConfig rely on).
 *
 * Holds the three astrx-suite HTTP engine base URLs the unified page fans out to
 * (websearch, onioncrawler, torrentds); the fourth "source" — internal AstrX
 * content — is served in-process by {@see \AstrX\Search\SiteSearchService} and
 * needs no URL. Each base URL is operator-controlled and MUST point at the
 * localhost engine; each is normalised to an http(s) origin (trailing slash
 * stripped) and any other scheme is rejected back to that engine's safe loopback
 * default, so a bad edit can never turn a federated fan-out into an SSRF
 * primitive. {@see FederatedSearchClient} only ever appends the fixed,
 * code-controlled path `/api/search?…` with a rawurlencode()d query — no
 * user-supplied bytes ever reach the host/scheme.
 */
final class FederatedSearchConfig
{
    public const string DEFAULT_WEBSEARCH_BASE_URL    = 'http://127.0.0.1:8803';
    public const string DEFAULT_ONIONCRAWLER_BASE_URL = 'http://127.0.0.1:8802';
    public const string DEFAULT_TORRENTDS_BASE_URL    = 'http://127.0.0.1:8804';

    /** Hard ceiling on the per-source network timeout, in seconds. */
    public const int MAX_TIMEOUT = 5;

    /** Hard ceiling on the results-per-source cap. */
    public const int MAX_PER_PAGE = 50;

    private string $websearchBaseUrl    = self::DEFAULT_WEBSEARCH_BASE_URL;
    private string $onioncrawlerBaseUrl = self::DEFAULT_ONIONCRAWLER_BASE_URL;
    private string $torrentdsBaseUrl    = self::DEFAULT_TORRENTDS_BASE_URL;
    private int    $timeoutSeconds      = 3;
    private int    $perPage             = 10;

    #[InjectConfig('websearch_base_url')]
    public function setWebsearchBaseUrl(string $v): void { $this->websearchBaseUrl = self::normaliseBase($v, self::DEFAULT_WEBSEARCH_BASE_URL); }

    #[InjectConfig('onioncrawler_base_url')]
    public function setOnioncrawlerBaseUrl(string $v): void { $this->onioncrawlerBaseUrl = self::normaliseBase($v, self::DEFAULT_ONIONCRAWLER_BASE_URL); }

    #[InjectConfig('torrentds_base_url')]
    public function setTorrentdsBaseUrl(string $v): void { $this->torrentdsBaseUrl = self::normaliseBase($v, self::DEFAULT_TORRENTDS_BASE_URL); }

    #[InjectConfig('timeout_seconds')]
    public function setTimeoutSeconds(int $v): void { $this->timeoutSeconds = max(1, min(self::MAX_TIMEOUT, $v)); }

    #[InjectConfig('per_page')]
    public function setPerPage(int $v): void { $this->perPage = max(1, min(self::MAX_PER_PAGE, $v)); }

    public function websearchBaseUrl(): string    { return $this->websearchBaseUrl; }
    public function onioncrawlerBaseUrl(): string { return $this->onioncrawlerBaseUrl; }
    public function torrentdsBaseUrl(): string    { return $this->torrentdsBaseUrl; }
    public function timeoutSeconds(): int         { return $this->timeoutSeconds; }
    public function perPage(): int                { return $this->perPage; }

    /**
     * Force a configured backend to a bare http(s) LOOPBACK origin. Rejects any
     * other scheme, any embedded userinfo (`user@host`), and any non-loopback host
     * back to the given loopback default, so a config typo can never turn a
     * federated fan-out into an SSRF primitive that reaches an off-box, LAN, or
     * cloud-metadata address. The value can only ever be a localhost HTTP endpoint.
     */
    private static function normaliseBase(string $v, string $default): string
    {
        $v = rtrim(trim($v), '/');
        if ($v === '') {
            return $default;
        }
        $parts = parse_url($v);
        if (!is_array($parts)) {
            return $default;
        }
        $scheme = isset($parts['scheme']) && is_string($parts['scheme']) ? strtolower($parts['scheme']) : '';
        if (!in_array($scheme, ['http', 'https'], true)) {
            return $default;
        }
        // Reject any embedded credentials — `http://127.0.0.1@evil.com` parses to
        // host=evil.com, so a userinfo segment is always an attempt to disguise
        // the real target.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return $default;
        }
        $host = isset($parts['host']) && is_string($parts['host']) ? strtolower($parts['host']) : '';
        if (!self::isLoopbackHost($host)) {
            return $default;
        }
        return $v;
    }

    /**
     * True only for a loopback host literal: `localhost`, the IPv6 loopback `::1`,
     * or any 127.0.0.0/8 IPv4 address. A hostname that would have to be resolved
     * (or any routable / metadata address) is rejected, so no off-box target is
     * reachable from a configured base URL.
     */
    private static function isLoopbackHost(string $host): bool
    {
        if ($host === 'localhost' || $host === '::1' || $host === '[::1]') {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return str_starts_with($host, '127.');
        }
        return false;
    }
}
