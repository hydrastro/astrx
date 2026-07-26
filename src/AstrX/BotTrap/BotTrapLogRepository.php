<?php
declare(strict_types=1);

namespace AstrX\BotTrap;

use AstrX\BotTrap\Diagnostic\BotTrapDbDiagnostic;
use AstrX\Result\Diagnostics;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\Result;
use PDO;
use PDOException;

/**
 * Data-access for `bot_trap_log`: one row per honeypot hit.
 *
 * Tor-safe by construction — the caller passes an ALREADY-hashed identity
 * (sha256 of the session id, or REMOTE_ADDR as a fallback); a raw IP is never
 * handed to, nor stored by, this repository. Free-form strings (path, UA,
 * referer) are truncated to their column widths so a hostile header cannot
 * overflow the row or error the INSERT.
 *
 * Native prepares (emulate_prepares=false) mean the LIMIT is bound as an int
 * (PDO::PARAM_INT); casts go through mixed-safe helpers so it stays PHPStan
 * level-10 clean. No exceptions escape — every failure becomes a Result::err
 * carrying a BotTrap diagnostic.
 */
final class BotTrapLogRepository
{
    /** Widths mirror the `bot_trap_log` columns in src/setup/tables.sql. */
    private const int MAX_TEXT  = 255;
    private const int IDENT_LEN = 64;

    /** Hard ceiling on rows returned by recent(), independent of the caller. */
    private const int MAX_RECENT = 500;

    public function __construct(private readonly PDO $pdo) {}

    /**
     * Record a single trap hit. Values are truncated to the column widths; the
     * ident is expected to be a 64-char sha256 hex digest.
     *
     * @return Result<bool>
     */
    public function record(string $path, string $ua, string $referer, string $ident): Result
    {
        try {
            $this->pdo->prepare(
                'INSERT INTO bot_trap_log (path, user_agent, referer, ident)
                 VALUES (:path, :ua, :referer, :ident)'
            )->execute([
                ':path'    => mb_substr($path, 0, self::MAX_TEXT),
                ':ua'      => mb_substr($ua, 0, self::MAX_TEXT),
                ':referer' => mb_substr($referer, 0, self::MAX_TEXT),
                ':ident'   => mb_substr($ident, 0, self::IDENT_LEN),
            ]);
            return Result::ok(true);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /**
     * The most recent trap hits, newest first.
     *
     * @return Result<list<array<string,mixed>>>
     */
    public function recent(int $limit): Result
    {
        $limit = max(1, min(self::MAX_RECENT, $limit));
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, path, user_agent, referer, ident, created_at
                   FROM bot_trap_log
                  ORDER BY id DESC
                  LIMIT :lim'
            );
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            /** @var list<array<string,mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return Result::ok($rows);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /** @return Result<never> */
    private function err(PDOException $e): Result
    {
        return Result::err(null, Diagnostics::of(new BotTrapDbDiagnostic(
            'astrx.bottrap/db_error', DiagnosticLevel::ERROR, $e->getMessage()
        )));
    }
}
