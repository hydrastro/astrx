<?php
declare(strict_types=1);

namespace AstrX\Mail;

use AstrX\Mail\Diagnostic\TrustCheckFailedDiagnostic;
use AstrX\Mail\Diagnostic\TrustAddFailedDiagnostic;
use AstrX\Mail\Diagnostic\TrustRemoveFailedDiagnostic;
use AstrX\Mail\Diagnostic\TrustListFailedDiagnostic;
use AstrX\Result\Diagnostics;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\Result;
use PDO;

/**
 * Manages the per-user list of trusted email senders.
 *
 * A trusted sender's emails may display external images and resources.
 * Trust is per-user (mailbox owner), per-sender-address.
 *
 * Table: webmail_trusted_sender
 *   user_id       BINARY(16)   — FK to user.id
 *   sender_email  VARCHAR(320) — the trusted sender's address (lowercase)
 *   created_at    TIMESTAMP
 *   PRIMARY KEY (user_id, sender_email)
 */
final class WebmailTrustedSenderRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Check if a sender is trusted by this user.
     * @return Result<bool>
     */
    public function isTrusted(string $userId, string $senderEmail): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM `webmail_trusted_sender`
                 WHERE `user_id` = UNHEX(:uid)
                   AND `sender_email` = LOWER(:email)
                 LIMIT 1'
            );
            $stmt->execute([':uid' => $userId, ':email' => $senderEmail]);
            return Result::ok($stmt->fetchColumn() !== false);
        } catch (\Throwable $e) {
            return Result::err(false, Diagnostics::of(
                new TrustCheckFailedDiagnostic('astrx.mail/trust.check_failed', DiagnosticLevel::ERROR, $e->getMessage())
            ));
        }
    }

    /**
     * Add a sender to the trust list for this user.
     * Idempotent — no error if already trusted.
     * @return Result<true>
     */
    public function trust(string $userId, string $senderEmail): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT IGNORE INTO `webmail_trusted_sender`
                     (`user_id`, `sender_email`, `created_at`)
                 VALUES (UNHEX(:uid), LOWER(:email), NOW())'
            );
            $stmt->execute([':uid' => $userId, ':email' => $senderEmail]);
            return Result::ok(true);
        } catch (\Throwable $e) {
            return Result::err(false, Diagnostics::of(
                new TrustAddFailedDiagnostic('astrx.mail/trust.add_failed', DiagnosticLevel::ERROR, $e->getMessage())
            ));
        }
    }

    /**
     * Remove a sender from the trust list.
     * @return Result<true>
     */
    public function untrust(string $userId, string $senderEmail): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'DELETE FROM `webmail_trusted_sender`
                 WHERE `user_id` = UNHEX(:uid)
                   AND `sender_email` = LOWER(:email)'
            );
            $stmt->execute([':uid' => $userId, ':email' => $senderEmail]);
            return Result::ok(true);
        } catch (\Throwable $e) {
            return Result::err(false, Diagnostics::of(
                new TrustRemoveFailedDiagnostic('astrx.mail/trust.remove_failed', DiagnosticLevel::ERROR, $e->getMessage())
            ));
        }
    }

    /**
     * List all trusted senders for a user.
     * @return Result<list<string>>
     */
    public function listTrusted(string $userId): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT `sender_email` FROM `webmail_trusted_sender`
                 WHERE `user_id` = UNHEX(:uid)
                 ORDER BY `created_at` DESC'
            );
            $stmt->execute([':uid' => $userId]);
            return Result::ok($stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (\Throwable $e) {
            return Result::err([], Diagnostics::of(
                new TrustListFailedDiagnostic('astrx.mail/trust.list_failed', DiagnosticLevel::ERROR, $e->getMessage())
            ));
        }
    }
}