<?php
declare(strict_types=1);

namespace AstrX\GitBrowse;

use AstrX\Config\InjectConfig;

/**
 * Git browser link-through configuration.
 *
 * Bound to the 'GitBrowseConfig' section of GitBrowse.config.php via
 * #[InjectConfig] (the domain resolves to the parent namespace segment
 * 'GitBrowse' since there is no GitBrowseConfig.config.php — the same fallback
 * BotTrapConfig/WebSearchConfig rely on).
 *
 * gitweb is a standalone, server-rendered HTML app with NO JSON API, so this
 * module never reimplements or proxies it — it only links to it. `service_url`
 * is the operator-controlled, user-facing address of that gitweb service (its
 * loopback default, or whatever public/onion URL the operator exposes it at).
 * It is validated to an http(s) URL (trailing slash stripped); any other scheme
 * is rejected to the safe default, so the value dropped into the link's href can
 * only ever be an http(s) address — never `javascript:`/`data:`.
 */
final class GitBrowseConfig
{
    /** Safe fallback: the loopback gitweb service on its default port. */
    public const string DEFAULT_SERVICE_URL = 'http://127.0.0.1:8801';

    private string $serviceUrl = self::DEFAULT_SERVICE_URL;

    #[InjectConfig('service_url')]
    public function setServiceUrl(string $v): void { $this->serviceUrl = self::normaliseUrl($v); }

    public function serviceUrl(): string { return $this->serviceUrl; }

    /**
     * Force the configured target to a trailing-slash-trimmed http(s) URL
     * (scheme + host + optional path). Rejects any other scheme (and the empty
     * string) back to the loopback default.
     */
    private static function normaliseUrl(string $v): string
    {
        $v = rtrim(trim($v), '/');
        if ($v === '') {
            return self::DEFAULT_SERVICE_URL;
        }
        $scheme = parse_url($v, PHP_URL_SCHEME);
        $scheme = is_string($scheme) ? strtolower($scheme) : '';
        if (!in_array($scheme, ['http', 'https'], true)) {
            return self::DEFAULT_SERVICE_URL;
        }
        return $v;
    }
}
