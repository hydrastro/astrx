<?php
declare(strict_types=1);

namespace AstrX\Blocklist;

use AstrX\Config\InjectConfig;

/**
 * Blocklist-editor backend configuration.
 *
 * Bound to the 'BlocklistConfig' section of Blocklist.config.php via
 * #[InjectConfig] (the domain resolves to the parent namespace segment
 * 'Blocklist' since there is no BlocklistConfig.config.php — the same fallback
 * BotTrapConfig/WebSearchConfig rely on).
 *
 * Holds the two write-capable engines' base URLs (onioncrawler, torrentds) and
 * their ADMIN TOKENS. The base URLs are operator-controlled and MUST point at the
 * localhost engine; each is normalised to an http(s) origin (any other scheme is
 * rejected to its safe loopback default), so a bad edit can never turn a blocklist
 * push into an SSRF primitive. {@see BlocklistClient} only ever appends the fixed
 * control paths (`/blocklist`, `/api/block`) and the admin form's own kind/value.
 *
 * The tokens are SECRETS: they authorise a destructive control action on the
 * engines and are read server-side only. They are never placed in the template
 * context, never rendered, and never logged — the controller passes them nowhere
 * but into {@see BlocklistClient}, which puts them only in the outgoing request's
 * auth header/body to the loopback engine.
 */
final class BlocklistConfig
{
    public const string DEFAULT_ONIONCRAWLER_BASE_URL = 'http://127.0.0.1:8802';
    public const string DEFAULT_TORRENTDS_BASE_URL    = 'http://127.0.0.1:8804';

    /** Hard ceiling on the per-request network timeout, in seconds. */
    public const int MAX_TIMEOUT = 5;

    private string $onioncrawlerBaseUrl   = self::DEFAULT_ONIONCRAWLER_BASE_URL;
    private string $torrentdsBaseUrl      = self::DEFAULT_TORRENTDS_BASE_URL;
    private string $onioncrawlerAdminToken = '';
    private string $torrentdsAdminToken    = '';
    private int    $timeoutSeconds        = 3;

    #[InjectConfig('onioncrawler_base_url')]
    public function setOnioncrawlerBaseUrl(string $v): void { $this->onioncrawlerBaseUrl = self::normaliseBase($v, self::DEFAULT_ONIONCRAWLER_BASE_URL); }

    #[InjectConfig('torrentds_base_url')]
    public function setTorrentdsBaseUrl(string $v): void { $this->torrentdsBaseUrl = self::normaliseBase($v, self::DEFAULT_TORRENTDS_BASE_URL); }

    #[InjectConfig('onioncrawler_admin_token')]
    public function setOnioncrawlerAdminToken(string $v): void { $this->onioncrawlerAdminToken = trim($v); }

    #[InjectConfig('torrentds_admin_token')]
    public function setTorrentdsAdminToken(string $v): void { $this->torrentdsAdminToken = trim($v); }

    #[InjectConfig('timeout_seconds')]
    public function setTimeoutSeconds(int $v): void { $this->timeoutSeconds = max(1, min(self::MAX_TIMEOUT, $v)); }

    public function onioncrawlerBaseUrl(): string    { return $this->onioncrawlerBaseUrl; }
    public function torrentdsBaseUrl(): string       { return $this->torrentdsBaseUrl; }
    public function onioncrawlerAdminToken(): string { return $this->onioncrawlerAdminToken; }
    public function torrentdsAdminToken(): string    { return $this->torrentdsAdminToken; }
    public function timeoutSeconds(): int            { return $this->timeoutSeconds; }

    /**
     * Force a configured backend to a bare http(s) LOOPBACK origin. Rejects any
     * other scheme, any embedded userinfo (`user@host`), and any non-loopback host
     * back to the given loopback default. This is what stops a mistyped/edited base
     * URL from shipping the engine ADMIN TOKEN (X-Admin-Token / Bearer / body) to
     * an off-box, LAN, or cloud-metadata address — the value can only ever be a
     * localhost HTTP endpoint.
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
        // the real target (and would exfiltrate the admin token there).
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
     * (or any routable / metadata address) is rejected, so the admin token can only
     * ever be sent to a localhost engine.
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
