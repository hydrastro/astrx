<?php
declare(strict_types=1);

namespace AstrX\Captcha;

use AstrX\Captcha\Diagnostic\CaptchaDbDiagnostic;
use AstrX\Result\Diagnostics;
use AstrX\Result\Result;
use PDO;
use PDOException;
use AstrX\Result\DiagnosticLevel;

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
     * @return Result<bool>
     */
    public function store(string $id, string $text, int $expiresAt): Result
    {
        // Store the captcha answer as PLAINTEXT (lower-cased so verification
        // can do a straight case-insensitive compare).
        //
        // History: an earlier revision SHA-256-hashed the text "for security".
        // That had three problems:
        //   1. SHA-256 hex is 64 chars but `captcha`.`text` is VARCHAR(32) →
        //      every INSERT silently truncated, breaking verification AND
        //      failing the INSERT under MariaDB strict mode.
        //   2. The same plaintext answer is rendered into the image we send
        //      to the browser, so any "DB-only attacker who can read this
        //      column" can equally well OCR a screenshot of the form. The
        //      hash protected nothing.
        //   3. Iframe-reloadable captchas need to re-render the image on
        //      demand from the stored answer — impossible from a one-way
        //      hash.
        //
        // Plaintext it is. The whole row expires in 10 minutes anyway.
        $plain = strtolower($text);
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO `captcha` (`id`, `text`, `expires_at`)
                 VALUES (:id, :text, FROM_UNIXTIME(:expires_at))'
            );
            $stmt->execute([':id' => $id, ':text' => $plain, ':expires_at' => $expiresAt]);

            return Result::ok(true);
        } catch (PDOException $e) {
            return Result::err(null, $this->diagnostic($e));
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
            /** @var array<string,mixed> $row */
            return Result::ok([
                                  'text'       => is_scalar($row['text']) ? (string)$row['text'] : '',
                                  'expires_at' => is_int($row['expires_at']) ? $row['expires_at'] : 0,
                              ]);
        } catch (PDOException $e) {
            return Result::err(null, $this->diagnostic($e));
        }
    }

    /**
     * Delete a captcha by ID (called after successful verification).
     *
     * @return Result<bool>
     */
    public function delete(string $id): Result
    {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM `captcha` WHERE `id` = :id');
            $stmt->execute([':id' => $id]);

            return Result::ok(true);
        } catch (PDOException $e) {
            return Result::err(null, $this->diagnostic($e));
        }
    }

    /**
     * Delete all expired captcha tokens.
     * Called opportunistically on generate() to keep the table clean.
     *
     * @return Result<int> Number of rows deleted.
     */
    /**
     * Replace the answer text for an existing captcha row, atomically.
     *
     * The captcha id stays the same so the parent form's hidden input
     * remains valid; only the text the user is supposed to type changes.
     *
     * Abuse prevention is baked into the UPDATE itself: the WHERE clause
     * requires regen_count < :max AND last_regen_at IS NULL OR older than
     * :cooldown seconds. If either condition fails, the UPDATE matches
     * zero rows — the caller sees rowCount() === 0 and treats it as "no-op,
     * use the current image". This makes the limit race-free under concurrent
     * refresh attempts without needing an explicit lock.
     *
     * @return Result<bool>  true if the row was regenerated, false if rate-limited.
     */
    public function regenerate(
        string $id,
        string $newText,
        int    $maxRegens     = 5,
        int    $cooldownSecs  = 2,
    ): Result {
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE `captcha`
                    SET `text`          = :text,
                        `regen_count`   = `regen_count` + 1,
                        `last_regen_at` = NOW()
                  WHERE `id`            = :id
                    AND `regen_count`   < :max
                    AND (`last_regen_at` IS NULL
                         OR `last_regen_at` < (NOW() - INTERVAL :cool SECOND))'
            );
            $stmt->execute([
                ':text' => strtolower($newText),
                ':id'   => $id,
                ':max'  => $maxRegens,
                ':cool' => $cooldownSecs,
            ]);
            return Result::ok($stmt->rowCount() > 0);
        } catch (\PDOException $e) {
            return Result::err(false, Diagnostics::of(
                new CaptchaDbDiagnostic(
                    'astrx.captcha/regenerate_failed',
                    DiagnosticLevel::ERROR,
                ),
            ));
        }
    }

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
                                   'astrx.captcha/db_error', DiagnosticLevel::ERROR,
                                   $e->getMessage(),
                               ));
    }
}
