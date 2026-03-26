<?php

declare(strict_types = 1);

namespace AstrX\Auth;

use AstrX\Auth\Diagnostic\DiagnosticVisibilityDbDiagnostic;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\Diagnostics;
use AstrX\Result\Result;
use PDO;

/**
 * Manages per-group diagnostic code visibility in the diagnostic_visibility table.
 * A row (code, group_name) means that group can see that diagnostic code.
 * Absence of a row means denied (default deny).
 * ADMIN is never stored — full access is enforced in DiagnosticVisibilityChecker.
 */
final class DiagnosticVisibilityRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Check whether a single group can see a diagnostic code.
     * @return Result<bool>
     */
    public function canSee(string $code, string $groupName)
    : Result {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM `diagnostic_visibility`
                 WHERE `code` = :code AND `group_name` = :group
                 LIMIT 1'
            );
            $stmt->execute([':code' => $code, ':group' => $groupName]);

            return Result::ok($stmt->fetchColumn() !== false);
        } catch (\Throwable $e) {
            return Result::err(
                false,
                Diagnostics::of(
                    new DiagnosticVisibilityDbDiagnostic(
                        'astrx.auth/diag_visibility.db_error',
                        DiagnosticLevel::ERROR,
                        $e->getMessage()
                    )
                )
            );
        }
    }

    /**
     * Return all (code, group_name) visibility rows as a nested map:
     *   code → list<group_name>
     * Used by the admin UI to build the matrix.
     * @return Result<array<string, list<string>>>
     */
    public function all()
    : Result
    {
        try {
            $stmt = $this->pdo->query(
                'SELECT `code`, `group_name` FROM `diagnostic_visibility` ORDER BY `code`, `group_name`'
            );
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $map = [];
            foreach ($rows as $row) {
                $map[$row['code']][] = $row['group_name'];
            }

            return Result::ok($map);
        } catch (\Throwable $e) {
            return Result::err([],
                               Diagnostics::of(
                                   new DiagnosticVisibilityDbDiagnostic(
                                       'astrx.auth/diag_visibility.db_error',
                                       DiagnosticLevel::ERROR,
                                       $e->getMessage()
                                   )
                               ));
        }
    }

    /**
     * Replace the entire visibility matrix for a given group.
     * Deletes all existing rows for the group and inserts the new set.
     *
     * @param list<string> $codes
     *
     * @return Result<true>
     */
    public function setForGroup(string $groupName, array $codes)
    : Result {
        try {
            $this->pdo->beginTransaction();

            $del = $this->pdo->prepare(
                'DELETE FROM `diagnostic_visibility` WHERE `group_name` = :group'
            );
            $del->execute([':group' => $groupName]);

            if ($codes !== []) {
                $ins = $this->pdo->prepare(
                    'INSERT INTO `diagnostic_visibility` (`code`, `group_name`) VALUES (:code, :group)'
                );
                foreach ($codes as $code) {
                    $ins->execute([':code' => $code, ':group' => $groupName]);
                }
            }

            $this->pdo->commit();

            return Result::ok(true);
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            return Result::err(
                false,
                Diagnostics::of(
                    new DiagnosticVisibilityDbDiagnostic(
                        'astrx.auth/diag_visibility.db_error',
                        DiagnosticLevel::ERROR,
                        $e->getMessage()
                    )
                )
            );
        }
    }
}