<?php
declare(strict_types=1);

namespace AstrX\ErrorHandler;

use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\DiagnosticSinkInterface;
use AstrX\Result\DiagnosticsCollector;
use Throwable;
use ErrorException;
use function AstrX\Support\langDir;
use function AstrX\Support\templateDir;

final class ErrorHandler
{
    /**
     * PHP error levels that mean the request is over.
     *
     * The first four never reach a userland error handler at all (PHP jumps
     * straight to the shutdown function) and are matched in shutdownHandler();
     * the last two do reach errorHandler(). E_WARNING is deliberately NOT here —
     * see errorHandler().
     *
     * @var list<int>
     */
    private const array FATAL_TYPES = [
        E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR,
    ];

    /**
     * Everything recorded this request, warnings included. Diagnostics only —
     * printed by the dev dump, never a reason to change the response.
     *
     * @var array<int, Throwable>
     */
    private array $recorded = [];

    /**
     * The subset that ENDS the request: uncaught throwables and real fatals.
     * Only a non-empty list here produces a 500 and an error body.
     *
     * @var array<int, Throwable>
     */
    private array $fatals = [];

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
        // An uncaught throwable IS the end of the request — record AND escalate.
        $this->recorded[] = $e;
        $this->fatals[]   = $e;
        $this->sink->emit(new UncaughtThrowableDiagnostic(
                              id:             'astrx.error_handler/uncaught_throwable',
                              level:          DiagnosticLevel::EMERGENCY,
                              throwableClass: $e::class,
                              message:        $e->getMessage(),
                          ));
    }

    /**
     * Record a PHP error. RECORDING IS NOT ESCALATING.
     *
     * Every error that passes the mask goes to the diagnostic sink. Only the
     * levels in FATAL_TYPES additionally join $fatals, which is what turns the
     * response into a 500 with an error body.
     *
     * The production mask is E_ALL & ~E_NOTICE, so E_WARNING passes it. This
     * method used to put every such error into the one list the shutdown handler
     * escalated, which meant ANY warning replaced a perfectly good 200 with
     * "<h1>Internal Server Error</h1>" appended to the already-flushed body. The
     * concrete case: an email attachment whose content_type contains a newline
     * makes PHP refuse the header with an E_WARNING, and the victim's downloaded
     * file arrived with an HTML error block glued to the end of it.
     */
    public function errorHandler(int $errno, string $errstr, string $errfile, int $errline): bool
    {
        $mask = error_reporting();
        if (($mask & $errno) === 0) {
            return false;
        }

        $ex               = new ErrorException($errstr, 0, $errno, $errfile, $errline);
        $this->recorded[] = $ex;

        if (in_array($errno, self::FATAL_TYPES, true)) {
            $this->fatals[] = $ex;
        }

        // One ID for every PHP error, fatal or not: the LEVEL carries the
        // severity, and the id is what Diagnostics/core.<locale>.php keys its
        // renderer on — a second id would need an entry in every locale or the
        // status bar would show a bare key.
        $this->sink->emit(new UncaughtThrowableDiagnostic(
                              id:             'astrx.error_handler/php_error',
                              level:          self::levelFor($errno),
                              throwableClass: $ex::class,
                              message:        $errstr . " @ $errfile:$errline",
                          ));

        return true;
    }

    /** Diagnostic severity for a PHP error level — a warning is a warning. */
    private static function levelFor(int $errno): DiagnosticLevel
    {
        return match ($errno) {
            E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED => DiagnosticLevel::NOTICE,
            E_WARNING, E_USER_WARNING                                => DiagnosticLevel::WARNING,
            default                                                  => DiagnosticLevel::ERROR,
        };
    }

    public function shutdownHandler(): void
    {
        $last = error_get_last();
        if ($last !== null && in_array($last['type'], self::FATAL_TYPES, true)) {
            $ex               = new ErrorException($last['message'], 0, $last['type'], $last['file'], $last['line']);
            $this->recorded[] = $ex;
            $this->fatals[]   = $ex;

            $this->sink->emit(new UncaughtThrowableDiagnostic(
                                  id:             'astrx.error_handler/fatal_error',
                                  level:          DiagnosticLevel::EMERGENCY,
                                  throwableClass: $ex::class,
                                  message:        $last['message'] . " @ {$last['file']}:{$last['line']}",
                              ));
        }

        // Warnings and notices stop here: they are in the sink (and in the status
        // bar / logs), and the response the request already produced stands.
        if ($this->fatals === []) {
            return;
        }

        // The body is already on the wire. Appending an error document now cannot
        // change the status code and can only corrupt what was sent — an image, a
        // JSON payload, a downloaded attachment. The fatal is in the sink; leave
        // the response alone.
        if (headers_sent()) {
            return;
        }

        // Discard whatever was buffered: a fatal means that output is a partial
        // render, and the client would otherwise receive half a page followed by
        // the error page. Only reached when a fatal actually occurred.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // A controller may have announced the length of the body we just threw
        // away; leaving it set makes the front end truncate the error page to
        // that many bytes.
        header_remove('Content-Length');

        http_response_code(500);

        [$title, $message] = $this->failsafeText();

        // Operator override: drop a failsafe.html into the template dir to
        // replace this minimal page. $title and $message are in scope for it.
        // None ships by default — the built-in page below needs no template
        // engine, no database and no config, which is the point of a failsafe.
        $failsafe = templateDir() !== '' ? (templateDir() . 'failsafe.html') : null;
        if ($failsafe !== null && is_file($failsafe)) {
            require $failsafe;
            return;
        }

        // Only show raw debug output in dev/test environments.
        // In production/staging, render a generic error page or fail silently.
        if ($this->env->isDevLike()) {
            echo "<body bgcolor='black' text='white'></body><h1>Error</h1><pre>";
            print_r($this->recorded);

            echo "\n\nDiagnostics (sink):\n";
            if ($this->sink instanceof DiagnosticsCollector) {
                print_r($this->sink->diagnostics()->toArray());
            }

            echo "</pre>";
            return;
        }

        $safeTitle   = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo "<!DOCTYPE html><html><head><meta charset=\"utf-8\">"
             . "<meta name=\"robots\" content=\"noindex,nofollow\">"
             . "<title>{$safeTitle}</title></head><body>"
             . "<h1>{$safeTitle}</h1><p>{$safeMessage}</p></body></html>";
    }

    /**
     * Title + message for the failsafe page, translated when that is still
     * possible.
     *
     * The strings are the Http domain's existing http.status.500.* entries, so
     * this adds no new translation keys and inherits the en/it parity the CI
     * gate already enforces. Everything here is wrapped: this runs while the
     * request is already dying, and a failsafe that can itself fail is not one.
     * The English fallback is the last resort, not the normal path.
     *
     * @return array{string, string}
     */
    private function failsafeText(): array
    {
        $fallback = ['Internal Server Error', 'An unexpected error occurred. Please try again later.'];

        try {
            $langDir = langDir();
            if ($langDir === '') {
                return $fallback;
            }

            $translator = new Translator($this->sink);
            $translator->setLocale(self::requestLocale($langDir));
            $translator->loadDomain($langDir, 'Http');

            return [
                $translator->t('http.status.500.name', fallback: $fallback[0]),
                $translator->t('http.status.500.message', fallback: $fallback[1]),
            ];
        } catch (Throwable) {
            return $fallback;
        }
    }

    /**
     * The locale of the request being served, from the first URL segment
     * (AstrX URLs are /<locale>/<page>).
     *
     * The router's parsed locale is not reachable from here — a fatal can happen
     * before the router runs at all — so this re-derives it, and only trusts the
     * segment when a matching lang directory exists. Without that check a request
     * for /../../x would put an attacker-chosen string into a Translator path.
     */
    private static function requestLocale(string $langDir): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (!is_string($uri) || preg_match('#^/([A-Za-z]{2}(?:-[A-Za-z]{2})?)(?:/|\?|$)#', $uri, $m) !== 1) {
            return 'en';
        }

        $candidate = $m[1];
        return is_dir(rtrim($langDir, '/\\') . DIRECTORY_SEPARATOR . $candidate) ? $candidate : 'en';
    }
}
