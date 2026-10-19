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
     * The active, unexpired ban in force against this poster right now, if any.
     * A ban matches on the account (user_id) OR the packed IP — honouring the
     * ban's stored CIDR prefix_len — and must be scoped to THIS board or be a
     * global (board_id IS NULL) ban. Returns the matching row, or null when the
     * poster is not banned. Called on the post write-path to reject before create.
     *
     * The small candidate set (active bans in scope) is fetched and the account /
     * CIDR match finished in PHP: a correct prefix comparison on VARBINARY reads
     * far clearer here than in SQL, and moderation ban lists are tiny. The raw
     * `ip` bytes are returned for the in-PHP mask; nothing binary is displayed.
     *
     * @return Result<array<string,mixed>|null>
     */
    public function findActiveFor(?string $hexUserId, ?string $packedIp, int $boardId): Result
    {
        $uid = ($hexUserId !== null && $hexUserId !== '' && ctype_xdigit($hexUserId))
            ? strtolower($hexUserId)
            : null;
        if ($uid === null && $packedIp === null) {
            return Result::ok(null);   // nothing to match on
        }
        try {
            $stmt = $this->pdo->prepare(
                'SELECT LOWER(HEX(user_id)) AS user_id, ip, prefix_len, reason,
                        UNIX_TIMESTAMP(expires_at) AS expires_ts
                   FROM board_ban
                  WHERE active = 1
                    AND (board_id IS NULL OR board_id = :b)
                    AND (expires_at IS NULL OR expires_at > NOW())
                    AND (user_id IS NOT NULL OR ip IS NOT NULL)'
            );
            $stmt->execute([':b' => $boardId]);
            /** @var list<array<string,mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $banUid = is_string($row['user_id'] ?? null) ? $row['user_id'] : '';
                if ($uid !== null && $banUid !== '' && $banUid === $uid) {
                    return Result::ok($row);
                }
                $banIp = $row['ip'] ?? null;
                if ($packedIp !== null && is_string($banIp) && $banIp !== '') {
                    $plen = is_numeric($row['prefix_len'] ?? null) ? (int) $row['prefix_len'] : 128;
                    if (self::ipInCidr($packedIp, $banIp, $plen)) {
                        return Result::ok($row);
                    }
                }
            }
            return Result::ok(null);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /**
     * True if a packed (inet_pton) IP falls inside a packed network / prefix.
     * Compares the leading whole bytes, then the partial byte under a bit mask.
     * A length mismatch (v4 vs v6) never matches.
     */
    private static function ipInCidr(string $ip, string $network, int $prefixLen): bool
    {
        $len = strlen($ip);
        if ($len !== strlen($network) || ($len !== 4 && $len !== 16)) {
            return false;
        }
        $prefixLen = max(0, min($len * 8, $prefixLen));
        if ($prefixLen === 0) {
            return true;
        }
        $fullBytes = intdiv($prefixLen, 8);
        if ($fullBytes > 0 && strncmp($ip, $network, $fullBytes) !== 0) {
            return false;
        }
        $remBits = $prefixLen % 8;
        if ($remBits === 0) {
            return true;
        }
        $mask = (~0 << (8 - $remBits)) & 0xFF;
        return (ord($ip[$fullBytes]) & $mask) === (ord($network[$fullBytes]) & $mask);
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
