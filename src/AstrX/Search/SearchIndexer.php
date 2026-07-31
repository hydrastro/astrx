<?php
declare(strict_types=1);

namespace AstrX\Search;

use AstrX\Result\Diagnostics;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\Result;
use AstrX\Search\Diagnostic\SearchDbDiagnostic;
use PDO;
use PDOException;
use Throwable;

/**
 * On-demand crawler that populates the `search_index` FULLTEXT table from the
 * four searchable sources (news, pages, comments, imageboard posts) via
 * {@see SearchSources}.
 *
 * Runnable three ways, all funnelling through the same rebuild():
 *   1. Admin button → a background `exec()` of tools/search_index.php.
 *   2. Standalone CLI → `php tools/search_index.php`.
 *   3. Admin request → requestRebuild() flags the job; cron runs
 *      `tools/search_index.php --if-requested`.
 *
 * There is NO index-on-write: content is only (re)indexed when a rebuild is
 * triggered. Between crawls, SiteSearchService covers freshly-created content
 * with a live LIKE fallback, so nothing is invisible in the meantime.
 *
 * A single-row `search_index_job` table tracks state (idle / requested /
 * running) plus the last doc count and timings, so every entry point and the
 * admin page share one view of what is happening.
 */
final class SearchIndexer
{
    /** Rows pulled from each source per batch — bounds peak memory + tx size. */
    private const int CRAWL_BATCH = 500;

    /**
     * Backstop batch cap PER SOURCE. The real infinite-loop guard is the
     * cursor-advance check in crawlType(); this is a second belt-and-braces
     * ceiling so a pathological data/driver condition still terminates. At
     * CRAWL_BATCH rows each it bounds a single source to ~10M rows.
     */
    private const int MAX_BATCHES_PER_TYPE = 20000;

    /** Global runaway ceiling across all sources — stop indexing past this. */
    private const int MAX_DOCS = 2000000;

    /** The single job-status row's primary key. */
    private const int JOB_ID = 1;

    public function __construct(
        private readonly PDO           $pdo,
        private readonly SearchSources $sources,
    ) {}

    // =========================================================================
    // Rebuild
    // =========================================================================

    /**
     * Rebuild the index from every source, on demand.
     *
     * Hardened against the two failure modes that made an earlier version appear
     * to "never stop": (1) it no longer wraps the WHOLE crawl in one giant
     * transaction — each batch commits on its own, so a large site can't exhaust
     * the undo log / lock-wait budget or OOM mid-run and leave the job wedged in
     * 'running'; (2) crawlType() stops the instant its keyset cursor fails to
     * advance, and a per-source batch cap plus a global doc ceiling hard-bound
     * total work. Rather than DELETE-then-reinsert, it UPSERTs every live row
     * (stamping indexed_at) then sweeps rows not refreshed this run — so
     * deleted/hidden content drops out without a big destructive transaction.
     *
     * @param (callable(string): void)|null $progress optional per-step line sink (CLI)
     * @return Result<int> the number of indexed documents
     */
    public function rebuild(?callable $progress = null): Result
    {
        $this->markRunning();

        $count  = 0;
        $capped = false;
        try {
            // Sweep cutoff, read from the DB clock so it lines up with the
            // CURRENT_TIMESTAMP each UPSERT writes to indexed_at.
            $runStart = $this->dbNow();

            $upsert = $this->pdo->prepare(
                "INSERT INTO `search_index`
                     (`doc_type`, `ref_id`, `title`, `body`, `url`, `doc_time`)
                 VALUES (:dt, :rid, :title, :body, :url, :dtime)
                 ON DUPLICATE KEY UPDATE
                     `title`      = VALUES(`title`),
                     `body`       = VALUES(`body`),
                     `url`        = VALUES(`url`),
                     `doc_time`   = VALUES(`doc_time`),
                     `indexed_at` = CURRENT_TIMESTAMP"
            );

            // Source types crawled to completion this run — the ONLY types it is
            // safe to sweep (see below).
            $sweepTypes = [];
            foreach (SearchSources::TYPES as $type) {
                if ($count >= self::MAX_DOCS) {
                    $capped = true;
                    break;
                }
                $result  = $this->crawlType($type, $upsert, $count, $progress);
                $count  += $result['written'];
                if ($result['complete']) {
                    $sweepTypes[] = $type;
                }
                if ($progress !== null) {
                    $progress($type . ': indexed ' . $result['written'] . ' (total ' . $count . ')');
                }
            }

            // Mark-and-sweep: drop rows not refreshed this run (their source is
            // gone/hidden). R4-22: restricted to the source types crawled to
            // COMPLETION. A type truncated mid-crawl by the global MAX_DOCS cap
            // (or the per-source batch cap / cursor guard), or skipped entirely
            // once the cap was hit, still holds unseen-but-live rows, so sweeping
            // it would delete live content — scope the delete per doc_type and
            // only for types that finished. Skipped when the DB clock was
            // unreadable ($runStart empty).
            if ($runStart !== '' && $sweepTypes !== []) {
                $del = $this->pdo->prepare(
                    'DELETE FROM `search_index` WHERE `doc_type` = :dt AND `indexed_at` < :cut'
                );
                foreach ($sweepTypes as $sweepType) {
                    $del->bindValue(':dt',  $sweepType);
                    $del->bindValue(':cut', $runStart);
                    $del->execute();
                }
            }
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $message = $e->getMessage();
            $this->markIdle($count, mb_substr('error: ' . $message, 0, 255), success: false);
            return Result::err(0, Diagnostics::of(new SearchDbDiagnostic(
                'astrx.search/index_rebuild_failed',
                DiagnosticLevel::ERROR,
                $message,
            )));
        }

        $msg = $capped
            ? 'indexed ' . $count . ' (stopped at global cap ' . self::MAX_DOCS . ')'
            : 'indexed ' . $count . ' document(s)';
        $this->markIdle($count, $msg, success: true);
        return Result::ok($count);
    }

    /**
     * Crawl one source in keyset-paginated batches, committing per batch.
     *
     * Terminates on: an empty/short batch (normal end), a cursor that fails to
     * advance (infinite-refetch guard), the per-source batch cap, or the global
     * doc ceiling — so it can never spin forever.
     *
     * The returned `complete` flag says whether the source was crawled to its
     * natural end (empty/short batch) rather than cut short by a cap or the
     * cursor guard. rebuild() only sweeps types that completed (R4-22), so a
     * truncated source never has its unseen-but-live rows deleted.
     *
     * @param \PDOStatement $upsert prepared once by rebuild(), re-executed here
     * @param (callable(string): void)|null $progress
     * @return array{written:int,complete:bool}
     */
    private function crawlType(string $type, \PDOStatement $upsert, int $already, ?callable $progress): array
    {
        $afterId  = 0;
        $written  = 0;
        $complete = false;

        for ($batch = 0; $batch < self::MAX_BATCHES_PER_TYPE; $batch++) {
            if ($already + $written >= self::MAX_DOCS) {
                break; // global doc ceiling → truncated (not complete)
            }

            $docs = $this->fetchBatch($type, $afterId, self::CRAWL_BATCH);
            if ($docs === []) {
                $complete = true; // no more rows for this source
                break;
            }

            $startId = $afterId;
            $this->pdo->beginTransaction();
            foreach ($docs as $doc) {
                $rid = $doc['ref_id'];
                $upsert->bindValue(':dt',    $doc['doc_type']);
                $upsert->bindValue(':rid',   $rid, PDO::PARAM_INT);
                $upsert->bindValue(':title', mb_substr($doc['title'], 0, 255));
                $upsert->bindValue(':body',  $doc['body']);
                $upsert->bindValue(':url',   mb_substr($doc['url'], 0, 512));
                $upsert->bindValue(':dtime', $doc['doc_time'], PDO::PARAM_INT);
                $upsert->execute();
                $written++;
                if ($rid > $afterId) {
                    $afterId = $rid;
                }
            }
            $this->pdo->commit();

            // Infinite-loop guard: a full batch whose largest id did NOT move
            // past the previous cursor would refetch the same rows forever.
            if ($afterId <= $startId) {
                if ($progress !== null) {
                    $progress($type . ': cursor stalled at id ' . $afterId . ' — stopping (guard)');
                }
                break; // guard fired → treat as truncated (not complete)
            }
            if (count($docs) < self::CRAWL_BATCH) {
                $complete = true; // short batch → end of source
                break;
            }
        }

        return ['written' => $written, 'complete' => $complete];
    }

    /** DB server clock as 'Y-m-d H:i:s', or '' if unreadable (sweep then skipped). */
    private function dbNow(): string
    {
        try {
            $stmt = $this->pdo->query('SELECT NOW()');
            if ($stmt === false) {
                return '';
            }
            $v = $stmt->fetchColumn();
            return is_string($v) ? $v : '';
        } catch (PDOException) {
            return '';
        }
    }

    /**
     * @return list<array{doc_type:string,ref_id:int,title:string,body:string,url:string,doc_time:int}>
     */
    private function fetchBatch(string $type, int $afterId, int $limit): array
    {
        return match ($type) {
            SearchSources::TYPE_NEWS     => $this->sources->crawlNews($afterId, $limit),
            SearchSources::TYPE_PAGES    => $this->sources->crawlPages($afterId, $limit),
            SearchSources::TYPE_COMMENTS => $this->sources->crawlComments($afterId, $limit),
            SearchSources::TYPE_BOARD    => $this->sources->crawlBoard($afterId, $limit),
            default                      => [],
        };
    }

    // =========================================================================
    // Request (cron path)
    // =========================================================================

    /**
     * Flag that a rebuild is wanted without running it now. Idempotent, and a
     * no-op while a crawl is already running. `tools/search_index.php
     * --if-requested` (cron) picks this up on its next tick.
     *
     * @return Result<bool>
     */
    public function requestRebuild(): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO `search_index_job` (`id`, `status`, `requested_at`)
                 VALUES (:id, 'requested', NOW())
                 ON DUPLICATE KEY UPDATE
                     `status`       = IF(`status` = 'running', `status`, 'requested'),
                     `requested_at` = IF(`status` = 'running', `requested_at`, NOW())"
            );
            $stmt->bindValue(':id', self::JOB_ID, PDO::PARAM_INT);
            $stmt->execute();
            return Result::ok(true);
        } catch (PDOException $e) {
            return Result::err(false, Diagnostics::of(new SearchDbDiagnostic(
                'astrx.search/index_request_failed',
                DiagnosticLevel::ERROR,
                $e->getMessage(),
            )));
        }
    }

    // =========================================================================
    // Status
    // =========================================================================

    /**
     * Current job row plus live index statistics, for the admin page and CLI.
     *
     * @return array{
     *     status:string, doc_count:int, live_count:int, message:string,
     *     requested_at:int, started_at:int, finished_at:int,
     *     indexed_at:int, newest_content:int, stale:bool
     * }
     */
    public function status(): array
    {
        $row = $this->jobRow();

        $liveCount = $this->scalarInt('SELECT COUNT(*) FROM `search_index`');
        $indexedAt = $this->scalarInt(
            'SELECT UNIX_TIMESTAMP(MAX(`indexed_at`)) FROM `search_index`'
        );
        $newest = $this->newestContentTime();

        // Stale when unindexed newer content exists, or when there is content
        // to index but the index is still empty.
        $stale = ($newest > $indexedAt) || ($indexedAt === 0 && $liveCount === 0 && $newest > 0);

        return [
            'status'         => $row['status'],
            'doc_count'      => $row['doc_count'],
            'live_count'     => $liveCount,
            'message'        => $row['message'],
            'requested_at'   => $row['requested_at'],
            'started_at'     => $row['started_at'],
            'finished_at'    => $row['finished_at'],
            'indexed_at'     => $indexedAt,
            'newest_content' => $newest,
            'stale'          => $stale,
        ];
    }

    /**
     * @return array{status:string,doc_count:int,message:string,requested_at:int,started_at:int,finished_at:int}
     */
    private function jobRow(): array
    {
        $default = [
            'status'       => 'idle',
            'doc_count'    => 0,
            'message'      => '',
            'requested_at' => 0,
            'started_at'   => 0,
            'finished_at'  => 0,
        ];

        try {
            $stmt = $this->pdo->query(
                "SELECT `status`, `doc_count`, `message`,
                        UNIX_TIMESTAMP(`requested_at`) AS requested_at,
                        UNIX_TIMESTAMP(`started_at`)   AS started_at,
                        UNIX_TIMESTAMP(`finished_at`)  AS finished_at
                   FROM `search_index_job`
                  WHERE `id` = " . self::JOB_ID . " LIMIT 1"
            );
            if ($stmt === false) {
                return $default;
            }
            /** @var array<string,mixed>|false $r */
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($r)) {
                return $default;
            }
            return [
                'status'       => $this->s($r['status'] ?? 'idle'),
                'doc_count'    => $this->i($r['doc_count'] ?? 0),
                'message'      => $this->s($r['message'] ?? ''),
                'requested_at' => $this->i($r['requested_at'] ?? 0),
                'started_at'   => $this->i($r['started_at'] ?? 0),
                'finished_at'  => $this->i($r['finished_at'] ?? 0),
            ];
        } catch (PDOException) {
            return $default;
        }
    }

    /** Newest timestamp across the time-bearing sources (pages have none). */
    private function newestContentTime(): int
    {
        $newest = 0;
        foreach ([
            'SELECT UNIX_TIMESTAMP(MAX(created_at)) FROM news WHERE hidden = 0',
            'SELECT UNIX_TIMESTAMP(MAX(c.created_at)) FROM comment c
               JOIN page pg ON pg.id = c.page_id
              WHERE c.hidden = 0 AND pg.hidden = 0',
            'SELECT UNIX_TIMESTAMP(MAX(p.created_at)) FROM board_post p
               JOIN board b ON b.id = p.board_id AND b.active = 1
              WHERE p.banned = 0',
        ] as $sql) {
            $newest = max($newest, $this->scalarInt($sql));
        }
        return $newest;
    }

    // =========================================================================
    // Job-row transitions
    // =========================================================================

    /**
     * Flag the job running. Called at the start of rebuild(), and by the admin
     * controller the moment it spawns a background crawl so the status page
     * reflects 'running' immediately, before the child process gets going.
     */
    public function markRunning(): void
    {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO `search_index_job` (`id`, `status`, `started_at`, `message`)
                 VALUES (:id, 'running', NOW(), '')
                 ON DUPLICATE KEY UPDATE
                     `status`     = 'running',
                     `started_at` = NOW(),
                     `message`    = ''"
            );
            $stmt->bindValue(':id', self::JOB_ID, PDO::PARAM_INT);
            $stmt->execute();
        } catch (PDOException) {
            // Non-fatal: the crawl itself is the source of truth; a status-row
            // write failure must not abort indexing.
        }
    }

    /**
     * Force the single job row back to a clean idle state.
     *
     * Operator recovery hatch for the admin page when a crawl has wedged in
     * 'running' — e.g. the background child was killed mid-run, or php-fpm's
     * PHP_BINARY got spawned instead of a CLI php and the child died on the spot
     * — leaving the status stuck so the Rebuild button self-suppresses forever.
     * Unlike {@see markIdle()} this is a public, Result-returning entry point
     * (mirrors {@see requestRebuild()}); it leaves doc_count untouched and only
     * clears status/finished_at/message, stamping the neutral marker 'reset'.
     *
     * @return Result<bool>
     */
    public function resetJob(): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                "UPDATE `search_index_job`
                    SET `status` = 'idle', `finished_at` = NOW(), `message` = 'reset'
                  WHERE `id` = :id"
            );
            $stmt->bindValue(':id', self::JOB_ID, PDO::PARAM_INT);
            $stmt->execute();
            return Result::ok(true);
        } catch (PDOException $e) {
            return Result::err(false, Diagnostics::of(new SearchDbDiagnostic(
                'astrx.search/index_reset_failed',
                DiagnosticLevel::ERROR,
                $e->getMessage(),
            )));
        }
    }

    private function markIdle(int $docCount, string $message, bool $success): void
    {
        try {
            $sql = $success
                ? "UPDATE `search_index_job`
                      SET `status` = 'idle', `doc_count` = :dc,
                          `finished_at` = NOW(), `message` = :msg
                    WHERE `id` = :id"
                : "UPDATE `search_index_job`
                      SET `status` = 'idle', `finished_at` = NOW(), `message` = :msg
                    WHERE `id` = :id";
            $stmt = $this->pdo->prepare($sql);
            if ($success) {
                $stmt->bindValue(':dc', $docCount, PDO::PARAM_INT);
            }
            $stmt->bindValue(':msg', $message);
            $stmt->bindValue(':id',  self::JOB_ID, PDO::PARAM_INT);
            $stmt->execute();
        } catch (PDOException) {
            // Non-fatal, as above.
        }
    }

    // =========================================================================
    // Small helpers
    // =========================================================================

    private function scalarInt(string $sql): int
    {
        try {
            $stmt = $this->pdo->query($sql);
            if ($stmt === false) {
                return 0;
            }
            return $this->i($stmt->fetchColumn());
        } catch (PDOException) {
            return 0;
        }
    }

    private function s(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : '';
    }

    private function i(mixed $v): int
    {
        return is_int($v) ? $v : (is_numeric($v) ? (int) $v : 0);
    }
}
