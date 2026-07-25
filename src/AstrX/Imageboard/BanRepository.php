<?php
declare(strict_types=1);

namespace AstrX\Imageboard;

use AstrX\Imageboard\Diagnostic\ImageboardDbDiagnostic;
use AstrX\Result\Diagnostics;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\Result;
use PDO;
use PDOException;

/**
 * Data-access for `board_ban`. A ban may key on an account (user_id), an IP /
 * CIDR range (ip + prefix_len), or both; it is board-scoped (board_id) or
 * global (board_id NULL). On a Tor hidden service the IP is one shared proxy
 * for everyone, so the account ban is the durable lever there — but both are
 * supported for the mixed clearnet/onion deployments AstrX targets.
 *
 * BINARY(16) columns are read as LOWER(HEX(...)) and written with UNHEX(?);
 * UNHEX(NULL) yields NULL, so a null hex id simply produces a NULL column.
 */
final class BanRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Active bans in force for a board: its own plus every global ban.
     * `ip` is returned hex-encoded (HEX) so no raw binary rides in the row —
     * the caller decodes it for display.
     *
     * @return Result<list<array<string,mixed>>>
     */
    public function activeForBoard(int $boardId): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT b.id AS ban_id, b.board_id,
                        LOWER(HEX(b.user_id)) AS user_id,
                        HEX(b.ip) AS ip_hex, b.prefix_len,
                        b.reason, b.note,
                        UNIX_TIMESTAMP(b.expires_at) AS expires_ts,
                        u.username
                   FROM board_ban b
              LEFT JOIN `user` u ON u.id = b.user_id
                  WHERE b.active = 1 AND (b.board_id IS NULL OR b.board_id = :b)
                  ORDER BY b.created_at DESC, b.id DESC'
            );
            $stmt->execute([':b' => $boardId]);
            /** @var list<array<string,mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return Result::ok($rows);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /**
     * Issue a ban. Any of the account / IP levers may be set; board_id NULL
     * makes it global. `days` computes the expiry: 0 (or less) = permanent
     * (NULL expires_at), otherwise NOW + N days. The hex ids are decoded with
     * UNHEX so a null passes straight through to a NULL column.
     *
     * @return Result<bool>
     */
    public function create(
        ?int    $boardId,
        ?string $hexUserId,
        ?string $packedIp,
        int     $prefixLen,
        string  $reason,
        string  $note,
        ?int    $postId,
        ?string $hexCreatedBy,
        int     $days,
    ): Result {
        // `days` is a controller-clamped int (never raw input), so embedding it
        // in the interval expression is safe; the value still binds separately.
        $expiresExpr = $days > 0 ? 'DATE_ADD(NOW(), INTERVAL :days DAY)' : 'NULL';
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO board_ban
                    (board_id, user_id, ip, prefix_len, reason, note, post_id, created_by, expires_at, active)
                 VALUES
                    (:board, UNHEX(:uid), :ip, :plen, :reason, :note, :postid, UNHEX(:cby), ' . $expiresExpr . ', 1)'
            );
            $params = [
                ':board'  => $boardId,
                ':uid'    => $hexUserId,
                ':ip'     => $packedIp,
                ':plen'   => max(0, min(128, $prefixLen)),
                ':reason' => mb_substr($reason, 0, 255),
                ':note'   => mb_substr($note, 0, 255),
                ':postid' => $postId,
                ':cby'    => $hexCreatedBy,
            ];
            if ($days > 0) {
                $params[':days'] = $days;
            }
            $stmt->execute($params);
            return Result::ok(true);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /**
     * Lift a ban (deactivate). The row is kept for the audit trail rather than
     * deleted.
     *
     * @return Result<bool>
     */
    public function lift(int $banId): Result
    {
        try {
            $this->pdo->prepare('UPDATE board_ban SET active = 0 WHERE id = :id')
                ->execute([':id' => $banId]);
            return Result::ok(true);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /** @return Result<never> */
    private function err(PDOException $e): Result
    {
        return Result::err(null, Diagnostics::of(new ImageboardDbDiagnostic(
            'astrx.imageboard/db_error', DiagnosticLevel::ERROR, $e->getMessage()
        )));
    }
}
