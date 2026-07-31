<?php
declare(strict_types=1);

namespace AstrX\Invite;

use AstrX\Invite\Diagnostic\InviteDbDiagnostic;
use AstrX\Result\Diagnostics;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\Result;
use PDO;
use PDOException;

/**
 * Data access for invite-only registration (the `invite` table).
 *
 * Each invite is a single-use, admin-issued token. `claim()` is the ONLY
 * enforcement point: it atomically flips unused → used (UPDATE … WHERE code = :c
 * AND used_at IS NULL) so a race or a replay can never double-use a code — the
 * losing request sees rowCount() === 0. `attributeTo()` records the consuming
 * user afterwards, and `release()` un-claims a code if the account creation it
 * was claimed for then fails. `isValid()` is a read-only UX early-out only.
 *
 * All queries are bound; native prepares mean integer columns come back as ints.
 * User ids are 32-char lowercase hex (bin2hex(random_bytes(16))); they are stored
 * as BINARY(16) via UNHEX() and never interpolated into SQL.
 *
 * @phpstan-type InviteRow array{id:int,code:string,note:string,created_at:string,used_at:?string,status:string}
 */
final class InviteRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Create a new (unused) invite. $adminHexId is the issuing admin's 32-char
     * hex id, stored for audit; null (or a non-hex value) records no issuer.
     *
     * @return Result<bool>
     */
    public function create(string $code, string $note, ?string $adminHexId): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO `invite` (`code`, `note`, `created_by`) VALUES (:c, :n, UNHEX(:b))'
            );
            $stmt->bindValue(':c', $code);
            $stmt->bindValue(':n', $note);
            // UNHEX(NULL) = NULL, so a missing/invalid issuer stores a NULL created_by.
            if ($adminHexId !== null && $this->isHex32($adminHexId)) {
                $stmt->bindValue(':b', $adminHexId);
            } else {
                $stmt->bindValue(':b', null, PDO::PARAM_NULL);
            }
            $stmt->execute();
            return Result::ok(true);
        } catch (PDOException $e) {
            return Result::err(false, $this->diag($e));
        }
    }

    /**
     * Every invite, newest first, for the admin list.
     *
     * @return Result<list<InviteRow>>
     */
    public function all(): Result
    {
        try {
            $stmt = $this->pdo->query(
                'SELECT `id`, `code`, `note`, `created_at`, `used_at`
                   FROM `invite`
                  ORDER BY `created_at` DESC, `id` DESC'
            );
            $out = [];
            if ($stmt !== false) {
                /** @var array<string,mixed> $r */
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $usedAt = (isset($r['used_at']) && is_scalar($r['used_at'])) ? (string) $r['used_at'] : null;
                    $used   = $usedAt !== null && $usedAt !== '';
                    $out[]  = [
                        'id'         => $this->i($r['id'] ?? null),
                        'code'       => $this->s($r['code'] ?? null),
                        'note'       => $this->s($r['note'] ?? null),
                        'created_at' => $this->s($r['created_at'] ?? null),
                        'used_at'    => $used ? $usedAt : null,
                        'status'     => $used ? 'used' : 'available',
                    ];
                }
            }
            return Result::ok($out);
        } catch (PDOException $e) {
            return Result::err([], $this->diag($e));
        }
    }

    /**
     * Delete an UNUSED invite. Returns true only when a row was actually removed
     * (a used or unknown id yields false — a used token is never revocable).
     *
     * @return Result<bool>
     */
    public function revoke(int $id): Result
    {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM `invite` WHERE `id` = :i AND `used_at` IS NULL');
            $stmt->bindValue(':i', $id, PDO::PARAM_INT);
            $stmt->execute();
            return Result::ok($stmt->rowCount() === 1);
        } catch (PDOException $e) {
            return Result::err(false, $this->diag($e));
        }
    }

    /**
     * Whether $code names an invite that exists and has not been used yet.
     * Read-only: this does NOT spend the code (see claim()).
     *
     * @return Result<bool>
     */
    public function isValid(string $code): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM `invite` WHERE `code` = :c AND `used_at` IS NULL LIMIT 1'
            );
            $stmt->bindValue(':c', $code);
            $stmt->execute();
            return Result::ok($stmt->fetchColumn() !== false);
        } catch (PDOException $e) {
            return Result::err(false, $this->diag($e));
        }
    }

    /**
     * Atomically CLAIM an invite: flip unused → used (no user recorded yet). Only
     * the request that wins the flip gets rowCount() === 1; a concurrent claim, a
     * replay, or an already-used/unknown code yields false. This is the single
     * enforcement point — pair it with attributeTo() after the account is created,
     * or release() if creation then fails. Claiming BEFORE register (rather than a
     * read-then-consume after) is what closes the double-use race.
     *
     * @return Result<bool>
     */
    public function claim(string $code): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE `invite` SET `used_at` = NOW() WHERE `code` = :c AND `used_at` IS NULL'
            );
            $stmt->bindValue(':c', $code);
            $stmt->execute();
            return Result::ok($stmt->rowCount() === 1);
        } catch (PDOException $e) {
            return Result::err(false, $this->diag($e));
        }
    }

    /**
     * Record which user a claimed code belongs to (audit backfill), after a
     * successful register. Only sets an as-yet-unattributed code, so it can never
     * overwrite an already-recorded consumer.
     *
     * @return Result<bool>
     */
    public function attributeTo(string $code, string $userHexId): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE `invite` SET `used_by` = UNHEX(:u) WHERE `code` = :c AND `used_by` IS NULL'
            );
            if ($this->isHex32($userHexId)) {
                $stmt->bindValue(':u', $userHexId);
            } else {
                $stmt->bindValue(':u', null, PDO::PARAM_NULL);
            }
            $stmt->bindValue(':c', $code);
            $stmt->execute();
            return Result::ok($stmt->rowCount() === 1);
        } catch (PDOException $e) {
            return Result::err(false, $this->diag($e));
        }
    }

    /**
     * Release a claimed code back to unused — called when the account creation a
     * claim() was made for then fails, so a transient error doesn't burn the code.
     * Only un-claims a still-unattributed code (used_by IS NULL), so it can never
     * resurrect a genuinely-consumed one.
     *
     * @return Result<bool>
     */
    public function release(string $code): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE `invite` SET `used_at` = NULL WHERE `code` = :c AND `used_by` IS NULL'
            );
            $stmt->bindValue(':c', $code);
            $stmt->execute();
            return Result::ok($stmt->rowCount() === 1);
        } catch (PDOException $e) {
            return Result::err(false, $this->diag($e));
        }
    }

    // -------------------------------------------------------------------------

    private function isHex32(string $s): bool
    {
        return strlen($s) === 32 && ctype_xdigit($s);
    }

    private function diag(PDOException $e): Diagnostics
    {
        return Diagnostics::of(new InviteDbDiagnostic('astrx.invite/db_error', DiagnosticLevel::ERROR, $e->getMessage()));
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
