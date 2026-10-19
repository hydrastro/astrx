<?php
declare(strict_types=1);

namespace AstrX\Database\Diagnostic;

use AstrX\Database\ConnectionFailure;
use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;
use PDOException;

/**
 * Emitted when the framework cannot open its database connection.
 *
 * This is the sanitisation boundary between PDO and everything that renders a
 * diagnostic. A PDOException goes into fromException(); only four things come
 * out, and every one of them is safe to put in front of a user:
 *
 *   driver()     the PDO driver name from PDO.db_type ('mysql') — not a secret
 *   reason()     a ConnectionFailure classification derived from the codes
 *   sqlState()   errorInfo[0], a five-character standard code
 *   driverCode() errorInfo[1], the driver's error number
 *
 * The exception's MESSAGE is deliberately not among them and is not stored on
 * this object at all, so no future catalog entry can render it by accident. It
 * embeds the host on a resolve failure and the account name plus the calling
 * host on an auth failure; see initPDO() in ContentManager for the full
 * reasoning about which rendering paths are and are not admin-only.
 */
final class DatabaseUnavailableDiagnostic extends AbstractDiagnostic
{
    public function __construct(
        string $id,
        DiagnosticLevel $level,
        private readonly string $driver,
        private readonly ConnectionFailure $reason,
        private readonly string $sqlState,
        private readonly int $driverCode,
    ) {
        parent::__construct($id, $level);
    }

    /**
     * Build the diagnostic from the exception PDO threw, keeping the codes and
     * dropping the message.
     *
     * PDOException reports connection failures two different ways: with an
     * errorInfo triple [SQLSTATE, driver code, message] for anything the driver
     * actually spoke to, and with errorInfo = null plus code 0 for a failure
     * inside PDO itself. Both are handled; an absent SQLSTATE stays '' and an
     * absent driver number stays 0 rather than being invented.
     */
    public static function fromException(
        string $id,
        DiagnosticLevel $level,
        string $driver,
        PDOException $e,
    ): self {
        $info = $e->errorInfo ?? [];

        $sqlStateRaw = $info[0] ?? null;
        $sqlState    = is_scalar($sqlStateRaw) ? (string) $sqlStateRaw : '';

        $driverCodeRaw = $info[1] ?? null;
        $driverCode    = is_numeric($driverCodeRaw) ? (int) $driverCodeRaw : 0;

        // Without an errorInfo triple the SQLSTATE-or-driver-number lives in
        // Exception::getCode(), which PDO types as int for connection errors.
        if ($driverCode === 0) {
            $code       = $e->getCode();
            $driverCode = is_numeric($code) ? (int) $code : 0;
        }

        return new self(
            $id,
            $level,
            $driver,
            ConnectionFailure::classify($sqlState, $driverCode),
            $sqlState,
            $driverCode,
        );
    }

    /** The PDO driver name from PDO.db_type, e.g. 'mysql'. */
    public function driver(): string
    {
        return $this->driver;
    }

    /** What went wrong, classified from the codes — never from the message. */
    public function reason(): ConnectionFailure
    {
        return $this->reason;
    }

    /** The standard five-character SQLSTATE, or '' when the driver gave none. */
    public function sqlState(): string
    {
        return $this->sqlState;
    }

    /** The driver's own error number (MySQL 1045, 2002, …), or 0 when absent. */
    public function driverCode(): int
    {
        return $this->driverCode;
    }
}
