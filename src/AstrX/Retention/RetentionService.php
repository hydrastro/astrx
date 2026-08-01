<?php
declare(strict_types=1);

namespace AstrX\Retention;

use AstrX\Chat\ChatConfig;
use AstrX\Imageboard\ImageboardConfig;
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

    public function __construct(
        private readonly PDO              $pdo,
        private readonly ImageboardConfig $imageboardConfig,
        private readonly ChatConfig       $chatConfig,
    ) {
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
        // Also GC the blobs whose DB row is gone (deleted/expired/pruned content):
        // the FK cascades drop board_image / chat_attachment rows but not the files.
        $out['orphan_files'] = $this->reapOrphanFiles();
        return $out;
    }

    /**
     * Reap orphaned upload files — blobs on disk whose owning DB row no longer
     * exists (a board post/thread was deleted, a chat message expired/was purged;
     * the cascade removed the row but not the file). This is the authoritative
     * garbage-collector behind the shredding promise; it catches orphans from every
     * source, including ones an immediate-unlink path missed or a crash left behind.
     *
     * SAFE BY CONSTRUCTION:
     *   - the referenced-name set is built from BOTH tables and the whole reap is
     *     ABORTED if any query errors — a DB hiccup can never cause a mass delete,
     *     and it stays correct even if the board and chat upload dirs coincide;
     *   - only files matching the exact random upload-name pattern (32 hex + ext)
     *     are candidates, so a stray/config file in the dir is never touched;
     *   - files newer than a 1-hour margin are skipped, so an upload whose row is
     *     still being written is never raced.
     *
     * @return int files removed
     */
    public function reapOrphanFiles(): int
    {
        $referenced = $this->referencedUploadNames();
        if ($referenced === null) {
            return 0; // a query failed — never delete on partial knowledge
        }
        $dirs = array_values(array_unique(array_filter([
            $this->imageboardConfig->uploadDir(),
            $this->chatConfig->uploadDir(),
        ], static fn(string $d): bool => $d !== '')));

        $removed = 0;
        foreach ($dirs as $dir) {
            $removed += $this->reapDir($dir, $referenced);
        }
        return $removed;
    }

    /**
     * The set of every filename referenced by an upload table. Returns null if any
     * query errors, so the caller aborts rather than treating "no rows" as "all
     * orphaned".
     *
     * @return array<string,true>|null
     */
    private function referencedUploadNames(): ?array
    {
        $set = [];
        $queries = [
            'SELECT `full_name` AS n FROM `board_image` UNION SELECT `thumb_name` FROM `board_image`',
            'SELECT `stored_name` AS n FROM `chat_attachment`',
        ];
        foreach ($queries as $sql) {
            try {
                $stmt = $this->pdo->query($sql);
                if ($stmt === false) {
                    return null;
                }
                while (true) {
                    $v = $stmt->fetchColumn();
                    if ($v === false) {
                        break;
                    }
                    if (is_string($v) && $v !== '') {
                        $set[$v] = true;
                    }
                }
            } catch (\PDOException) {
                return null;
            }
        }
        return $set;
    }

    /**
     * Unlink orphaned files in one directory. Only names matching the upload
     * pattern and absent from $referenced and older than the safety margin.
     *
     * @param array<string,true> $referenced
     */
    private function reapDir(string $dir, array $referenced): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $entries = @scandir($dir);
        if ($entries === false) {
            return 0;
        }
        $cutoff  = time() - 3600;
        $removed = 0;
        foreach ($entries as $name) {
            if (preg_match('/^[0-9a-f]{32}\.[A-Za-z0-9]{1,8}$/', $name) !== 1
                || isset($referenced[$name])) {
                continue;
            }
            $path = $dir . '/' . $name;
            if (!is_file($path)) {
                continue;
            }
            $mtime = @filemtime($path);
            if ($mtime !== false && $mtime > $cutoff) {
                continue; // too fresh — an in-flight upload's row may still be committing
            }
            if (@unlink($path)) {
                $removed++;
            }
        }
        return $removed;
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
