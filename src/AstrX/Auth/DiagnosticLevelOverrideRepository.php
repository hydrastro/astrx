<?php
declare(strict_types=1);

namespace AstrX\Auth;

use AstrX\Auth\Diagnostic\DiagnosticVisibilityDbDiagnostic;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\Diagnostics;
use AstrX\Result\Result;
use PDO;

/**
 * Manages per-code diagnostic level overrides in the diagnostic_level_override table.
 *
 * When a row exists for a code, its stored level replaces the level passed
 * by the emitting class. When no row exists, the class-declared level stands.
 */
final class DiagnosticLevelOverrideRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Return all overrides as a map: code → DiagnosticLevel.
     * Loaded once per request by DiagnosticVisibilityChecker.
     *
     * @return Result<array<string, DiagnosticLevel>>
     */
    public function all(): Result
    {
        try {
            $stmt = $this->pdo->query(
                'SELECT `code`, `level` FROM `diagnostic_level_override`'
            );
            assert($stmt !== false);
            /** @var list<array<string,mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            /** @var array<string, DiagnosticLevel> $map */
            $map  = [];
            foreach ($rows as $row) {
                $level = DiagnosticLevel::tryFrom((int) $row['level']);
                if ($level !== null) {
                    $map[(string)$row['code']] = $level;
                }
            }
            return Result::ok($map);
        } catch (\Throwable $e) {
            return Result::err([], Diagnostics::of(new DiagnosticVisibilityDbDiagnostic(
                                                       'astrx.auth/diag_level_override.db_error', DiagnosticLevel::ERROR, $e->getMessage()
                                                   )));
        }
    }

    /**
     * Set the level override for a single code.
     * Pass null to remove the override entirely.
     *
     * @return Result<bool>
     */
    public function set(string $code, ?DiagnosticLevel $level): Result
    {
        try {
            if ($level === null) {
                $stmt = $this->pdo->prepare(
                    'DELETE FROM `diagnostic_level_override` WHERE `code` = :code'
                );
                $stmt->execute([':code' => $code]);
            } else {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO `diagnostic_level_override` (`code`, `level`)
                     VALUES (:code, :level)
                     ON DUPLICATE KEY UPDATE `level` = :level'
                );
                $stmt->execute([':code' => $code, ':level' => $level->value]);
            }
            return Result::ok(true);
        } catch (\Throwable $e) {
            return Result::err(null, Diagnostics::of(new DiagnosticVisibilityDbDiagnostic(
                                                          'astrx.auth/diag_level_override.db_error', DiagnosticLevel::ERROR, $e->getMessage()
                                                      )));
        }
    }

    /**
     * Replace the entire override table in a single transaction.
     * Accepts a map of code → level int value (or null to clear).
     *
     * @param array<string, int|null> $overrides
     * @return Result<bool>
     */
    public function replaceAll(array $overrides): Result
    {
        try {
            $this->pdo->beginTransaction();

            $this->pdo->exec('DELETE FROM `diagnostic_level_override`');

            if ($overrides !== []) {
                $ins = $this->pdo->prepare(
                    'INSERT INTO `diagnostic_level_override` (`code`, `level`) VALUES (:code, :level)'
                );
                foreach ($overrides as $code => $levelValue) {
                    $level = DiagnosticLevel::tryFrom((int) $levelValue);
                    if ($level !== null) {
                        $ins->execute([':code' => $code, ':level' => $level->value]);
                    }
                }
            }

            $this->pdo->commit();
            return Result::ok(true);
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            return Result::err(null, Diagnostics::of(new DiagnosticVisibilityDbDiagnostic(
                                                          'astrx.auth/diag_level_override.db_error', DiagnosticLevel::ERROR, $e->getMessage()
                                                      )));
        }
    }
}
