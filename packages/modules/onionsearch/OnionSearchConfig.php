<?php
declare(strict_types=1);

namespace AstrX\OnionSearch;

use AstrX\Config\InjectConfig;

/**
 * Onion search backend configuration.
 *
 * Bound to the 'OnionSearchConfig' section of OnionSearch.config.php via
 * #[InjectConfig] (the domain resolves to the parent namespace segment
 * 'OnionSearch' since there is no OnionSearchConfig.config.php — the same
 * fallback BotTrapConfig/ChatConfig rely on).
 *
 * The base URL is operator-controlled and MUST point at the localhost engine
 * (the onioncrawler `search` server, which itself owns the Tor SOCKS hop —
 * AstrX only ever talks to loopback HTTP). It is normalised here to an http(s)
 * origin (trailing slash stripped); any other scheme is rejected to the safe
 * default so a bad edit can never turn the bridge into an SSRF primitive. The
 * request builder only ever appends `/api/search?q=…&page=…`, and the end user
 * controls only q and page — never the host or scheme.
 */
final class OnionSearchConfig
{
    /** Safe fallback origin: the loopback engine on its default port. */
    public const string DEFAULT_BASE_URL = 'http://127.0.0.1:8802';

    /** Hard ceiling on the per-request network timeout, in seconds. */
    public const int MAX_TIMEOUT = 5;

    /** Hard ceiling on the results-per-page hint. */
    public const int MAX_PER_PAGE = 50;

    private string $baseUrl        = self::DEFAULT_BASE_URL;
    private int    $timeoutSeconds = 3;
    private int    $perPage        = 10;

    #[InjectConfig('base_url')]        public function setBaseUrl(string $v): void       { $this->baseUrl = self::normaliseBase($v); }
    #[InjectConfig('timeout_seconds')] public function setTimeoutSeconds(int $v): void   { $this->timeoutSeconds = max(1, min(self::MAX_TIMEOUT, $v)); }
    #[InjectConfig('per_page')]        public function setPerPage(int $v): void          { $this->perPage = max(1, min(self::MAX_PER_PAGE, $v)); }

    public function baseUrl(): string       { return $this->baseUrl; }
    public function timeoutSeconds(): int   { return $this->timeoutSeconds; }
    public function perPage(): int          { return $this->perPage; }

    /**
     * Force the configured backend to a bare http(s) origin. Rejects any other
     * scheme (and the empty string) back to the loopback default, so the value
     * can only ever be a localhost-style HTTP endpoint.
     */
    private static function normaliseBase(string $v): string
    {
        $v = rtrim(trim($v), '/');
        if ($v === '') {
            return self::DEFAULT_BASE_URL;
        }
        $scheme = parse_url($v, PHP_URL_SCHEME);
        $scheme = is_string($scheme) ? strtolower($scheme) : '';
        if (!in_array($scheme, ['http', 'https'], true)) {
            return self::DEFAULT_BASE_URL;
        }
        return $v;
    }
}
