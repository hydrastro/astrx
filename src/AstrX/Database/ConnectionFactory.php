<?php
declare(strict_types=1);

namespace AstrX\Database;

use AstrX\Config\Config;
use AstrX\Database\Diagnostic\DatabaseConfigIncompleteDiagnostic;
use AstrX\Database\Diagnostic\DatabaseUnavailableDiagnostic;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\Diagnostics;
use AstrX\Result\Result;
use PDO;
use PDOException;

/**
 * Opens the application's PDO connection from the `PDO` config section.
 *
 * One implementation for every entry point: the web request pipeline and the
 * maintenance tools (tools/search_index.php, tools/retention.php) all connect
 * through it, so they share the same rules: no guessable credential defaults,
 * a missing driver reported as such, and connection failures as typed
 * diagnostics rather than exceptions.
 */
final class ConnectionFactory
{
    /**
     * The database could not be opened. CRITICAL, not ERROR: this is the one
     * dependency without which no part of the request can proceed, so it must
     * outrank every other diagnostic the failed request collects and stay above
     * any status-bar threshold an operator sets.
     */
    public const string ID_DB_UNAVAILABLE = 'astrx.database/connect_failed';
    public const DiagnosticLevel LVL_DB_UNAVAILABLE = DiagnosticLevel::CRITICAL;

    /** PDO.config.php does not declare every credential — no connection is attempted. */
    public const string ID_DB_CONFIG_INCOMPLETE = 'astrx.database/config_incomplete';
    public const DiagnosticLevel LVL_DB_CONFIG_INCOMPLETE = DiagnosticLevel::CRITICAL;

    public function __construct(private readonly Config $config) {}

    /**
     * Open the request's database connection.
     *
     * This is the only place AstrX constructs a PDO, and every way it can fail
     * — a container that has not finished booting, a rotated password, a typo
     * in PDO.config.php, a PHP build without the driver — is a RECOVERABLE
     * RUNTIME error about the operator's environment, not a programmer-contract
     * violation. So it flows through Result<PDO> and the Diagnostics channel
     * like every other runtime failure in this framework, and `new PDO(...)` is
     * wrapped rather than left to throw. Unwrapped it produced a bare 500 in
     * production and, under environment=testing, a stack trace whose exception
     * message carried the database host and the account name.
     *
     * WHAT MAY REACH A RENDERED STRING, AND WHY
     *
     * The DSN, host, port, username and password never leave this method. The
     * diagnostic carries the driver name, a ConnectionFailure classification,
     * the SQLSTATE and the driver's error number — codes, all of them safe.
     *
     * The PDOException MESSAGE is dropped, not attached, because no rendering
     * path for a diagnostic in this framework is provably admin-only:
     *   - ErrorController's <details> panel is admin-only today
     *     (Gate::can(ADMIN_ACCESS)), but what it renders is the catalog string;
     *   - the same catalog string also reaches the page status bar through
     *     DiagnosticRenderer::renderFiltered() and API clients through
     *     JsonRenderer, and BOTH decide visibility with
     *     DiagnosticVisibilityChecker — i.e. with the operator-editable
     *     diagnostic_visibility table, which can grant any code to GUEST.
     * One admin ticking this code visible for guests would then publish
     * "Access denied for user 'astrx'@'10.0.0.7'" to anonymous visitors of a
     * hidden service. The codes lose nothing an operator needs: 1045 is a
     * rejected credential, 1049 a missing database, 2002 nothing listening —
     * and the host and user are in that operator's own PDO.config.php, which
     * the catalog message names.
     *
     * @return Result<PDO>
     */
    public function connect(): Result
    {
        // db_type keeps a default: it names a PHP driver, not a credential, and
        // every schema AstrX ships is MySQL/MariaDB DDL.
        $driver = $this->config->getConfigString('PDO', 'db_type', 'mysql');

        // The credentials have NO fallback, deliberately. The removed defaults
        // ('localhost' / 'content_manager' / 'user' / 'password') turned "this
        // install has no PDO.config.php" — the normal state of a fresh checkout,
        // since the file is gitignored — into "silently try a guessable account
        // against whatever is listening on localhost", and then reported the
        // resulting auth failure as though the configuration had been read.
        // Reading with no default also has Config emit its own
        // astrx.config/get_config.not_found for each absent key, naming it.
        $hostRaw     = $this->config->getConfig('PDO', 'db_host');
        $dbnameRaw   = $this->config->getConfig('PDO', 'db_name');
        $usernameRaw = $this->config->getConfig('PDO', 'db_username');
        $passwdRaw   = $this->config->getConfig('PDO', 'db_password');

        $host     = is_scalar($hostRaw)     ? (string) $hostRaw     : null;
        $dbname   = is_scalar($dbnameRaw)   ? (string) $dbnameRaw   : null;
        $username = is_scalar($usernameRaw) ? (string) $usernameRaw : null;
        // An empty password is a legitimate value (the shipped example uses
        // one); an ABSENT key is not, and the two must not resolve alike.
        $passwd   = is_scalar($passwdRaw)   ? (string) $passwdRaw   : null;

        if ($host === null || $dbname === null || $username === null || $passwd === null) {
            $missing = [];
            if ($host === null)     { $missing[] = 'db_host'; }
            if ($dbname === null)   { $missing[] = 'db_name'; }
            if ($username === null) { $missing[] = 'db_username'; }
            if ($passwd === null)   { $missing[] = 'db_password'; }

            return Result::err(null, Diagnostics::of(new DatabaseConfigIncompleteDiagnostic(
                self::ID_DB_CONFIG_INCOMPLETE,
                self::LVL_DB_CONFIG_INCOMPLETE,
                $missing,
            )));
        }

        // A driver this PHP was not built with makes PDO throw a message-only
        // exception ("could not find driver") with no SQLSTATE and no error
        // number. Decide it here, where the answer is certain, rather than by
        // sniffing that message downstream.
        if (!in_array($driver, PDO::getAvailableDrivers(), true)) {
            return Result::err(null, Diagnostics::of(new DatabaseUnavailableDiagnostic(
                self::ID_DB_UNAVAILABLE,
                self::LVL_DB_UNAVAILABLE,
                $driver,
                ConnectionFailure::DRIVER_MISSING,
                '',
                0,
            )));
        }

        $port     = $this->config->getConfigInt('PDO', 'db_port', 0);
        $portPart = $port > 0 ? ';port=' . $port : '';
        $dsn = $driver . ':host=' . $host . $portPart . ';dbname=' . $dbname . ';charset=utf8mb4';

        // getConfigBool, not getConfig+assert: assertions are compiled out in
        // production (zend.assertions=-1), so a hand-edited 'false' in
        // PDO.config.php used to reach setAttribute() as the truthy string it is.
        $emulate    = $this->config->getConfigBool('PDO', 'emulate_prepares', false);
        $errExc     = $this->config->getConfigBool('PDO', 'errmode_exception', true);
        $fetchAssoc = $this->config->getConfigBool('PDO', 'default_fetch_assoc', true);

        try {
            $pdo = new PDO($dsn, $username, $passwd);

            // Inside the try as well: a driver that rejects an attribute throws
            // the same PDOException, and a connection whose error mode never
            // took is not one this method should hand out.
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $emulate);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, $errExc ? PDO::ERRMODE_EXCEPTION : PDO::ERRMODE_SILENT);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, $fetchAssoc ? PDO::FETCH_ASSOC : PDO::FETCH_BOTH);
        } catch (PDOException $e) {
            return Result::err(null, Diagnostics::of(DatabaseUnavailableDiagnostic::fromException(
                self::ID_DB_UNAVAILABLE,
                self::LVL_DB_UNAVAILABLE,
                $driver,
                $e,
            )));
        }

        return Result::ok($pdo);
    }
}
