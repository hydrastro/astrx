<?php
declare(strict_types=1);

namespace AstrX\Database;

/**
 * Why a database connection could not be established — the SAFE half of a
 * PDOException.
 *
 * A PDOException thrown by `new PDO(...)` carries three things: a SQLSTATE, a
 * driver-specific error number, and a human message. The first two are pure
 * codes. The third is not safe to repeat: on a name-resolution failure it
 * contains the host ("getaddrinfo for db.internal failed"), and on an
 * authentication failure it contains the account and the calling host
 * ("Access denied for user 'astrx'@'10.0.0.7' (using password: YES)").
 *
 * This enum is the classification derived from the two codes, so the framework
 * can tell an operator WHAT went wrong without repeating WHO it was connecting
 * as or WHERE. The per-case sentence lives in the Diagnostics lang catalogs
 * (resources/lang/{locale}/Diagnostics/database.{locale}.php), never here.
 */
enum ConnectionFailure: string
{
    /** Nothing answered: host unresolvable, port closed, socket missing, server gone. */
    case UNREACHABLE = 'unreachable';

    /** The server answered and refused the credentials (or the host itself). */
    case AUTH_REJECTED = 'auth_rejected';

    /** The server answered, the credentials were fine, the database does not exist. */
    case UNKNOWN_DATABASE = 'unknown_database';

    /** The PDO driver named by PDO.db_type is not compiled into this PHP build. */
    case DRIVER_MISSING = 'driver_missing';

    /** Anything else — the SQLSTATE and driver code are reported as-is. */
    case UNKNOWN = 'unknown';

    /**
     * Classify a connection failure from its codes alone.
     *
     * MySQL/MariaDB driver numbers come first because that is the driver AstrX
     * ships DDL for and because MySQL reports several of these under the
     * catch-all SQLSTATE HY000. The SQLSTATE arms below them are the portable
     * fallback (they are what PostgreSQL and friends report), so a deployment on
     * another driver still gets a real classification rather than UNKNOWN.
     *
     * @param string $sqlState   errorInfo[0], e.g. 'HY000', '28000'; '' when absent
     * @param int    $driverCode errorInfo[1], e.g. 2002, 1045; 0 when absent
     */
    public static function classify(string $sqlState, int $driverCode): self
    {
        return match (true) {
            // ── MySQL / MariaDB client and server error numbers ──────────────
            // 2002 can't connect (socket), 2003 can't connect (TCP),
            // 2005 unknown host, 2006 server gone away, 2013 lost connection.
            in_array($driverCode, [2002, 2003, 2005, 2006, 2013], true) => self::UNREACHABLE,
            // 1044 access denied to database, 1045 access denied for user,
            // 1130 host not allowed, 1698 auth plugin refused.
            in_array($driverCode, [1044, 1045, 1130, 1698], true)       => self::AUTH_REJECTED,
            // 1049 unknown database.
            $driverCode === 1049                                        => self::UNKNOWN_DATABASE,

            // ── Portable SQLSTATE classes ────────────────────────────────────
            // 28000/28P01 invalid authorization specification / bad password.
            $sqlState === '28000', $sqlState === '28P01'                 => self::AUTH_REJECTED,
            // 3D000 invalid catalog (database) name.
            $sqlState === '3D000'                                        => self::UNKNOWN_DATABASE,
            // Class 08 — connection exception.
            str_starts_with($sqlState, '08')                             => self::UNREACHABLE,

            default                                                      => self::UNKNOWN,
        };
    }
}
