<?php
declare(strict_types=1);

namespace AstrX\Admin;

use AstrX\Admin\Diagnostic\AuditLogDiagnostic;
use AstrX\Result\Diagnostics;
use AstrX\Result\Result;
use AstrX\User\UserSession;
use PDO;
use AstrX\Result\DiagnosticLevel;

/**
 * Writes immutable audit log entries for significant admin actions.
 *
 * Each entry records who did what to which resource, when, and from where.
 * Entries are INSERT-only — never updated or deleted by the application.
 *
 * Table: admin_audit_log
 *   id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
 *   user_id     BINARY(16)   — FK to user.id (the admin who acted)
 *   username    VARCHAR(64)  — denormalised snapshot (user may be deleted later)
 *   action      VARCHAR(64)  — machine-readable verb, e.g. 'config.save'
 *   resource    VARCHAR(128) — what was acted on, e.g. 'Mail.config.php'
 *   detail      TEXT         — optional human-readable detail / diff summary
 *   ip          VARCHAR(45)  — IPv4 or IPv6
 *   created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
 */
final class AuditLogger
{
    public function __construct(
        private readonly PDO         $pdo,
        private readonly UserSession $session,
    ) {}

    /**
     * Record an admin action.
     *
     * @param string $action   e.g. 'config.save', 'user.ban', 'page.delete'
     * @param string $resource e.g. 'Mail', 'user:abc123', 'page:42'
     * @param string $detail   optional human-readable summary
     * @return Result<bool>
     */
    public function log(string $action, string $resource, string $detail = ''): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO `admin_audit_log`
                     (`user_id`, `username`, `action`, `resource`, `detail`, `ip`)
                 VALUES (UNHEX(:uid), :username, :action, :resource, :detail, :ip)'
            );
            $stmt->execute([
                               ':uid'      => $this->session->userId(),
                               ':username' => $this->session->username(),
                               ':action'   => $action,
                               ':resource' => $resource,
                               ':detail'   => $detail,
                               ':ip'       => $_SERVER['REMOTE_ADDR'] ?? '',
                           ]);
            return Result::ok(true);
        } catch (\Throwable $e) {
            return Result::err(null, Diagnostics::of(new AuditLogDiagnostic(
                                                          'astrx.admin/audit_log_write_failed', DiagnosticLevel::WARNING, $e->getMessage()
                                                      )));
        }
    }
}
