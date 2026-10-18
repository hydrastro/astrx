<?php
declare(strict_types=1);

namespace AstrX\Site;

use PDO;

/**
 * Operator switches stored in the `site_config` key/value table that the
 * request pipeline consults on every response: the canonical .onion address
 * and the panic lockdown.
 *
 * Every read is a single indexed lookup that degrades to "off"/'' on any
 * failure — including no database at all (the error path builds this with a
 * null connection) — because these values decorate or gate a response that
 * must still be produced.
 */
final class SiteFlags
{
    public function __construct(private readonly ?PDO $pdo) {}

    /**
     * R13: the operator-configured canonical .onion base URL for the
     * Onion-Location header, or '' when unset or invalid.
     */
    public function onionPrimary(): string
    {
        $s = trim($this->value('onion_primary') ?? '');
        // Only ever emit a plausible .onion http(s) URL — never reflect junk
        // into a response header.
        return preg_match('#^https?://[a-z2-7]{16,90}\.onion(/\S*)?$#i', $s) === 1 ? $s : '';
    }

    /** Is the operator's panic / lockdown switch on? (site_config panic_active = '1'). */
    public function panicActive(): bool
    {
        return $this->value('panic_active') === '1';
    }

    /** The operator-set lockdown message shown on the panic page, or a default. */
    public function panicMessage(): string
    {
        $v = $this->value('panic_message');
        if ($v !== null && trim($v) !== '') {
            return trim($v);
        }
        return 'This service is temporarily unavailable. Please check back later.';
    }

    /** One site_config value, or null when absent, non-string or unreadable. */
    private function value(string $key): ?string
    {
        if ($this->pdo === null) {
            return null;
        }
        try {
            $stmt = $this->pdo->prepare('SELECT `value` FROM `site_config` WHERE `key` = ? LIMIT 1');
            $stmt->execute([$key]);
            $v = $stmt->fetchColumn();
            return is_string($v) ? $v : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
