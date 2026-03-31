<?php
declare(strict_types=1);

namespace AstrX\ErrorHandler;

use AstrX\Result\DiagnosticLevel;
use AstrX\Result\DiagnosticSinkInterface;
use AstrX\Result\DiagnosticsCollector;
use Throwable;
use ErrorException;
use function AstrX\Support\templateDir;

final class ErrorHandler
{
    /** @var array<int, Throwable> */
    private array $exceptions = [];

    private DiagnosticSinkInterface $sink;

    /**
     * Safe default: production mode suppresses debug output.
     * Call setEnvironment() early in bootstrap to configure correctly.
     */
    private EnvironmentType $env = EnvironmentType::PRODUCTION;

    public function __construct(?DiagnosticSinkInterface $sink = null)
    {
        $this->sink = $sink ?? new DiagnosticsCollector();

        set_error_handler([$this, 'errorHandler']);
        set_exception_handler([$this, 'exceptionsHandler']);
        register_shutdown_function([$this, 'shutdownHandler']);
    }

    public function setDiagnosticSink(DiagnosticSinkInterface $sink): void
    {
        $this->sink = $sink;
    }

    public function setEnvironment(EnvironmentType $env): void
    {
        $this->env = $env;

        $policy = match ($env) {
            EnvironmentType::DEVELOPMENT, EnvironmentType::TESTING => [
                'display_errors'         => '1',
                'display_startup_errors' => '1',
                'error_reporting'        => E_ALL,
                'assert_active'          => 1,
            ],
            EnvironmentType::STAGING => [
                'display_errors'         => '0',
                'display_startup_errors' => '0',
                'error_reporting'        => E_ALL,
                'assert_active'          => 0,
            ],
            EnvironmentType::PRODUCTION => [
                'display_errors'         => '0',
                'display_startup_errors' => '0',
                'error_reporting'        => E_ALL & ~E_NOTICE,
                'assert_active'          => 0,
            ],
        };

        ini_set('display_errors', $policy['display_errors']);
        ini_set('display_startup_errors', $policy['display_startup_errors']);
        error_reporting($policy['error_reporting']);

        if ($env === EnvironmentType::DEVELOPMENT || $env === EnvironmentType::TESTING) {
            ini_set('assert.exception', '1');

            // Activate Xdebug if the extension is present.
            // In production Xdebug should not be installed at all; this block is
            // a no-op there even if the extension were loaded.
            // Docker: add the Xdebug dev-stage instructions to your Dockerfile
            // (see README / Dockerfile comments).
            if (extension_loaded('xdebug')) {
                ini_set('xdebug.mode',               'debug,develop');
                ini_set('xdebug.start_with_request', 'yes');
                // 'host.docker.internal' resolves to the Docker host on Mac/Windows.
                // On Linux either set this to the host gateway IP (e.g. 172.17.0.1)
                // or use: extra_hosts: ['host.docker.internal:host-gateway'] in
                // docker-compose.yml, which makes the hostname work on Linux too.
                ini_set('xdebug.client_host', 'host.docker.internal');
                ini_set('xdebug.client_port', '9003');
                ini_set('xdebug.log_level',   '0');  // 0 = errors only
            }
        }
    }

    public function exceptionsHandler(Throwable $e): void
    {
        $this->exceptions[] = $e;
        $this->sink->emit(new UncaughtThrowableDiagnostic(
                              id:             'astrx.error_handler/uncaught_throwable',
                              level:          DiagnosticLevel::EMERGENCY,
                              throwableClass: $e::class,
                              message:        $e->getMessage(),
                          ));
    }

    public function errorHandler(int $errno, string $errstr, string $errfile, int $errline): bool
    {
        $mask = error_reporting();
        if (($mask & $errno) === 0) {
            return false;
        }

        $ex                = new ErrorException($errstr, 0, $errno, $errfile, $errline);
        $this->exceptions[] = $ex;

        $this->sink->emit(new UncaughtThrowableDiagnostic(
                              id:             'astrx.error_handler/php_error',
                              level:          DiagnosticLevel::ERROR,
                              throwableClass: $ex::class,
                              message:        $errstr . " @ $errfile:$errline",
                          ));

        return true;
    }

    public function shutdownHandler(): void
    {
        $last = error_get_last();
        if ($last !== null) {
            $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
            if (in_array($last['type'], $fatalTypes, true)) {
                $ex                = new ErrorException($last['message'], 0, $last['type'], $last['file'], $last['line']);
                $this->exceptions[] = $ex;

                $this->sink->emit(new UncaughtThrowableDiagnostic(
                                      id:             'astrx.error_handler/fatal_error',
                                      level:          DiagnosticLevel::EMERGENCY,
                                      throwableClass: $ex::class,
                                      message:        $last['message'] . " @ {$last['file']}:{$last['line']}",
                                  ));
            }
        }

        if ($this->exceptions === []) {
            return;
        }

        http_response_code(500);

        $failsafe = templateDir() !== '' ? (templateDir() . 'failsafe.html') : null;

        if ($failsafe !== null && file_exists($failsafe)) {
            require $failsafe;
            return;
        }

        // Only show raw debug output in dev/test environments.
        // In production/staging, render a generic error page or fail silently.
        if ($this->env->isDevLike()) {
            echo "<body bgcolor='black' text='white'></body><h1>Error</h1><pre>";
            print_r($this->exceptions);

            echo "\n\nDiagnostics (sink):\n";
            if ($this->sink instanceof DiagnosticsCollector) {
                print_r($this->sink->diagnostics()->toArray());
            }

            echo "</pre>";
        } else {
            echo "<h1>Internal Server Error</h1>";
            echo "<p>An unexpected error occurred. Please try again later.</p>";
        }
    }
}
