<?php
declare(strict_types=1);

namespace AstrX\Retention;

use PDO;

/**
 * Data-retention / ephemerality engine.
 *
 * A single place to age-out or shred the tables that accumulate over time on a
 * hidden service. Two purge modes:
 *
 *   'age'    — the operator sets a window (days); rows older than it are deleted.
 *              Used for tables that would otherwise grow forever (honeypot log,
 *              sealed tips, chat reports). Window 0 = keep forever (the default,
 *              so nothing is ever deleted unless the operator opts in).
 *   'expiry' — the table already carries a per-row `expires_at` (chat messages /
 *              PMs, set from ChatConfig). Here we only run that existing GC eagerly
 *              on demand; the window itself stays owned by ChatConfig.
 *
 * SECURITY: every table/column name comes from the hardcoded {@see TARGETS}
 * registry — NEVER from request input — so identifier interpolation into SQL is
 * safe. Age cutoffs are computed in PHP and bound as parameters. Windows persist
 * in the `site_config` KV (retention_days_<key>).
 */
final class RetentionService
{
    /**
     * The retention registry. key → table/column/mode. Order defines display order.
     *
     * @var list<array{key:string,table:string,col:string,mode:string}>
     */
    private const TARGETS = [
        ['key' => 'bot_trap_log', 'table' => 'bot_trap_log', 'col' => 'created_at', 'mode' => 'age'],
        ['key' => 'tipline',      'table' => 'tipline',      'col' => 'created_at', 'mode' => 'age'],
        ['key' => 'chat_report',  'table' => 'chat_report',  'col' => 'created_at', 'mode' => 'age'],
        ['key' => 'chat_message', 'table' => 'chat_message', 'col' => 'expires_at', 'mode' => 'expiry'],
        ['key' => 'chat_pm',      'table' => 'chat_pm',      'col' => 'expires_at', 'mode' => 'expiry'],
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array{key:string,table:string,col:string,mode:string}>
     */
    public function targets(): array
    {
        return self::TARGETS;
    }

    /** @return array{key:string,table:string,col:string,mode:string}|null */
    private function resolve(string $key): ?array
    {
        foreach (self::TARGETS as $t) {
            if ($t['key'] === $key) {
                return $t;
            }
        }
        return null;
    }

    public function count(string $key): int
    {
        $t = $this->resolve($key);
        if ($t === null) {
            return 0;
        }
        try {
            // Identifier from the hardcoded registry only — safe to interpolate.
            $stmt = $this->pdo->query('SELECT COUNT(*) FROM `' . $t['table'] . '`');
            if ($stmt === false) {
                return 0;
            }
            $n = $stmt->fetchColumn();
            return is_numeric($n) ? (int) $n : 0;
        } catch (\PDOException) {
            return 0;
        }
    }

    /** Configured age window in days for an 'age' target (0 = keep forever). */
    public function window(string $key): int
    {
        $t = $this->resolve($key);
        if ($t === null || $t['mode'] !== 'age') {
            return 0;
        }
        return max(0, (int) $this->cfg('retention_days_' . $key));
    }

    public function setWindow(string $key, int $days): void
    {
        $t = $this->resolve($key);
        if ($t === null || $t['mode'] !== 'age') {
            return;
        }
        $this->put('retention_days_' . $key, (string) max(0, min(3650, $days)));
    }

    /** Delete rows older than $days for an 'age' target. Returns rows removed. */
    public function purgeAge(string $key, int $days): int
    {
        $t = $this->resolve($key);
        if ($t === null || $t['mode'] !== 'age' || $days < 1) {
            return 0;
        }
        $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);
        try {
            $stmt = $this->pdo->prepare(
                'DELETE FROM `' . $t['table'] . '` WHERE `' . $t['col'] . '` < :cutoff'
            );
            $stmt->execute([':cutoff' => $cutoff]);
            return $stmt->rowCount();
        } catch (\PDOException) {
            return 0;
        }
    }

    /** Run an 'expiry' target's existing GC (delete where expires_at <= NOW()). */
    public function purgeExpiry(string $key): int
    {
        $t = $this->resolve($key);
        if ($t === null || $t['mode'] !== 'expiry') {
            return 0;
        }
        try {
            $stmt = $this->pdo->query(
                'DELETE FROM `' . $t['table'] . '` WHERE `' . $t['col'] . '` <= NOW()'
            );
            return $stmt === false ? 0 : $stmt->rowCount();
        } catch (\PDOException) {
            return 0;
        }
    }

    /** Shred EVERY row of a target regardless of age. Returns rows removed. */
    public function purgeAll(string $key): int
    {
        $t = $this->resolve($key);
        if ($t === null) {
            return 0;
        }
        try {
            $stmt = $this->pdo->query('DELETE FROM `' . $t['table'] . '`');
            return $stmt === false ? 0 : $stmt->rowCount();
        } catch (\PDOException) {
            return 0;
        }
    }

    /**
     * Apply the configured retention to one target: age targets purge by their
     * window (skipped when 0), expiry targets run their GC. Returns rows removed.
     */
    public function shred(string $key): int
    {
        $t = $this->resolve($key);
        if ($t === null) {
            return 0;
        }
        if ($t['mode'] === 'age') {
            $days = $this->window($key);
            return $days > 0 ? $this->purgeAge($key, $days) : 0;
        }
        return $this->purgeExpiry($key);
    }

    /**
     * Apply retention to every target (for the "run now" button / cron).
     *
     * @return array<string,int> key → rows removed
     */
    public function runAll(): array
    {
        $out = [];
        foreach (self::TARGETS as $t) {
            $out[$t['key']] = $this->shred($t['key']);
        }
        return $out;
    }

    private function cfg(string $key): string
    {
        try {
            $stmt = $this->pdo->prepare('SELECT `value` FROM `site_config` WHERE `key` = :k LIMIT 1');
            $stmt->execute([':k' => $key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) { return ''; }
            /** @var array<string,mixed> $row */
            return is_scalar($row['value'] ?? null) ? (string) $row['value'] : '';
        } catch (\PDOException) {
            return '';
        }
    }

    private function put(string $key, string $value): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO `site_config` (`key`, `value`) VALUES (:k, :v)
                 ON DUPLICATE KEY UPDATE `value` = :v2'
            );
            $stmt->execute([':k' => $key, ':v' => $value, ':v2' => $value]);
        } catch (\PDOException) {
            // Non-fatal.
        }
    }
}
