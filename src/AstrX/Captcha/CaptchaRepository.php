<?php
declare(strict_types=1);

namespace AstrX\Captcha;

use AstrX\Captcha\Diagnostic\CaptchaDbDiagnostic;
use AstrX\Result\Diagnostics;
use AstrX\Result\Result;
use PDO;
use PDOException;

/**
 * Pure data-access layer for the `captcha` table.
 *
 * Schema (tables.sql):
 *   captcha(id CHAR(32) PK, text VARCHAR(32), expires_at TIMESTAMP)
 */
final class CaptchaRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Store a new captcha token.
     *
     * @return Result<true>
     */
    public function store(string $id, string $text, int $expiresAt): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO `captcha` (`id`, `text`, `expires_at`)
                 VALUES (:id, :text, FROM_UNIXTIME(:expires_at))'
            );
            $stmt->execute([':id' => $id, ':text' => $text, ':expires_at' => $expiresAt]);

            return Result::ok(true);
        } catch (PDOException $e) {
            return Result::err(false, $this->diagnostic($e));
        }
    }

    /**
     * Find a captcha by ID.
     *
     * @return Result<array{text:string, expires_at:int}|null>
     */
    public function find(string $id): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT `text`, UNIX_TIMESTAMP(`expires_at`) AS expires_at
                   FROM `captcha`
                  WHERE `id` = :id'
            );
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row === false) {
                return Result::ok(null);
            }

            return Result::ok([
                'text'       => (string) $row['text'],
                'expires_at' => (int)    $row['expires_at'],
            ]);
        } catch (PDOException $e) {
            return Result::err(null, $this->diagnostic($e));
        }
    }

    /**
     * Delete a captcha by ID (called after successful verification).
     *
     * @return Result<true>
     */
    public function delete(string $id): Result
    {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM `captcha` WHERE `id` = :id');
            $stmt->execute([':id' => $id]);

            return Result::ok(true);
        } catch (PDOException $e) {
            return Result::err(false, $this->diagnostic($e));
        }
    }

    /**
     * Delete all expired captcha tokens.
     * Called opportunistically on generate() to keep the table clean.
     *
     * @return Result<int> Number of rows deleted.
     */
    public function deleteExpired(): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'DELETE FROM `captcha` WHERE `expires_at` < NOW()'
            );
            $stmt->execute();

            return Result::ok($stmt->rowCount());
        } catch (PDOException $e) {
            return Result::err(0, $this->diagnostic($e));
        }
    }

    private function diagnostic(PDOException $e): Diagnostics
    {
        return Diagnostics::of(new CaptchaDbDiagnostic(
            CaptchaDbDiagnostic::ID,
            CaptchaDbDiagnostic::LEVEL,
            $e->getMessage(),
        ));
    }
}
