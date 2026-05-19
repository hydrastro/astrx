<?php

declare(strict_types = 1);

namespace AstrX\Controller;

use AstrX\Http\Request;
use AstrX\Http\Response;
use AstrX\Result\Diagnostics;
use AstrX\Result\Result;
use AstrX\Result\DiagnosticInterface;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Session\PrgHandler;

abstract class AbstractController implements Controller
{
    public function __construct(protected DiagnosticsCollector $collector)
    {
    }

    /** @return Result<null> */
    final protected function ok()
    : Result
    {
        return Result::ok(null);
    }

    /** @return Result<never> */
    final protected function err(mixed $error = null, ?Diagnostics $d = null)
    : Result {
        return Result::err($error, $d);
    }

    final protected function emit(DiagnosticInterface $d)
    : void {
        $this->collector->emit($d);
    }

    // -------------------------------------------------------------------------
    // PRG dispatch helper
    // -------------------------------------------------------------------------

    /**
     * Standard PRG-POST dispatch.
     *
     * If the request URL carries the PRG token query parameter, the supplied
     * processor is called with the token, then the user is redirected to
     * $selfUrl + the returned querystring (or empty string) and the script
     * exits. Otherwise returns false and the caller continues with the GET
     * rendering path.
     *
     * The processor signature is `function(string $prgToken): string` where
     * the returned string is an optional URL suffix (e.g. "?edit=3").
     */
    final protected function handlePrgPost(
        Request    $request,
        PrgHandler $prg,
        string     $selfUrl,
        callable   $processor,
    ): bool {
        $prgToken = $request->query()->get($prg->tokenQueryKey());
        if (!is_string($prgToken) || $prgToken === '') {
            return false;
        }

        /** @var string|null $suffix */
        $suffix = $processor($prgToken);
        $qs     = is_string($suffix) ? $suffix : '';

        Response::redirect($selfUrl . $qs)
            ->send()->drainTo($this->collector);
        exit;
    }

    // -------------------------------------------------------------------------
    // Type casting helpers
    // -------------------------------------------------------------------------

    /** Cast mixed→string safely for PHPStan level 10. */
    protected static function str(mixed $v, string $default = ''): string
    {
        return is_scalar($v) ? (string)$v : $default;
    }

    /** Cast mixed→int safely for PHPStan level 10. */
    protected static function int(mixed $v, int $default = 0): int
    {
        return is_int($v) ? $v : (is_numeric($v) ? (int)$v : $default);
    }

    /** Cast mixed→bool safely for PHPStan level 10. */
    protected static function bool(mixed $v): bool
    {
        return (bool)$v;
    }

    /**
     * @param array<string,mixed> $arr
     */
    protected static function mStr(array $arr, string $key, string $default = ''): string
    {
        $v = $arr[$key] ?? $default;
        return is_scalar($v) ? (string)$v : $default;
    }

    /**
     * @param array<string,mixed> $arr
     */
    protected static function mInt(array $arr, string $key, int $default = 0): int
    {
        $v = $arr[$key] ?? $default;
        return is_int($v) ? $v : (is_numeric($v) ? (int)$v : $default);
    }

    /**
     * @param array<string,mixed> $arr
     */
    protected static function mBool(array $arr, string $key, bool $default = false): bool
    {
        return !empty($arr[$key]);
    }

    /**
     * @param array<string,mixed> $arr
     * @return array<string,mixed>
     */
    protected static function mArray(array $arr, string $key): array
    {
        $v = $arr[$key] ?? [];
        if (!is_array($v)) { return []; }
        /** @var array<string,mixed> $v */
        return $v;
    }

    /**
     * Read a posted field, trim it, return null if empty.
     * Useful for adminUpdate-style methods that expect nullable strings.
     *
     * @param array<string,mixed> $arr
     */
    protected static function mNullableTrimmed(array $arr, string $key): ?string
    {
        $v = $arr[$key] ?? null;
        if (!is_scalar($v)) { return null; }
        $trimmed = trim((string)$v);
        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Read a query-string parameter as int with a default.
     * Replaces the inline `is_numeric($vfoo = $req->query()->get('x')) ? (int)$vfoo : 0` pattern.
     */
    protected static function queryInt(Request $request, string $key, int $default = 0): int
    {
        $v = $request->query()->get($key);
        return is_int($v) ? $v : (is_numeric($v) ? (int)$v : $default);
    }

    /**
     * Read a query-string parameter as a trimmed string.
     */
    protected static function queryStr(Request $request, string $key, string $default = ''): string
    {
        $v = $request->query()->get($key);
        return is_scalar($v) ? trim((string)$v) : $default;
    }
}
