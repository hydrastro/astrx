<?php
declare(strict_types=1);

namespace AstrX\Blocklist;

/**
 * The blocklist bridge: a zero-dependency HTTP client that pushes an admin
 * blocklist entry to the two write-capable astrx-suite engines — onioncrawler
 * (`POST /blocklist`) and torrentds (`POST /api/block`). No curl, no Composer —
 * plain stream wrappers with a short timeout and a hard body cap.
 *
 * SECURITY MODEL
 * --------------
 *   * Every target host + scheme comes only from {@see BlocklistConfig}
 *     (operator-trusted, normalised to an http(s) origin). Only the fixed control
 *     paths (`/blocklist`, `/api/block`) are appended. The admin-supplied `kind`
 *     and `value` travel as POST BODY fields (http_build_query-encoded), never
 *     spliced into a URL — so no user byte reaches the host/scheme. No SSRF
 *     surface.
 *   * The engine ADMIN TOKEN comes only from config and is sent every accepted
 *     way at once — the `X-Admin-Token` header, an `Authorization: Bearer` header
 *     AND the `token` body field — so it works whichever shape the engine checks.
 *     CR/LF are stripped from the token before it is placed in a header, so even a
 *     malformed configured token cannot inject a header. The token is never
 *     returned, rendered or logged.
 *   * Redirects are never followed (`follow_location: 0`) and the response body is
 *     capped at {@see MAX_BODY} under a total wall-clock deadline, so a hostile or
 *     huge or slow-drip endpoint cannot chase a redirect off-box or hang the admin
 *     page — timeouts AND response sizes are bounded.
 *   * Every network failure is swallowed (`@` keeps the warning out of AstrX's
 *     error mask, else a 500) and surfaces as a friendly `unreachable` status —
 *     never a crash. Each target's outcome is reported independently.
 *
 * This class has NO AstrX dependencies beyond {@see BlocklistConfig}, so it can be
 * exercised in isolation (see tests/suite_bridge_test.php).
 */
final class BlocklistClient
{
    /** Hard cap on a control response body (defensive; these bodies are tiny). */
    private const int MAX_BODY = 1 << 18; // 256 KiB

    public function __construct(private readonly BlocklistConfig $config) {}

    /**
     * Push a blocklist entry to onioncrawler's `POST /blocklist`.
     * Contract: kind=host|keyword, value=…, token via header/field/Bearer.
     *
     * @return array{ok:bool, status:string, http_status:int}
     */
    public function blockOnion(string $kind, string $value): array
    {
        return $this->submit(
            $this->config->onioncrawlerBaseUrl() . '/blocklist',
            $this->config->onioncrawlerAdminToken(),
            $kind,
            $value,
        );
    }

    /**
     * Push a blocklist entry to torrentds' `POST /api/block`.
     * Contract: kind=infohash|keyword, value=…, token via field/header/Bearer.
     *
     * @return array{ok:bool, status:string, http_status:int}
     */
    public function blockTorrent(string $kind, string $value): array
    {
        return $this->submit(
            $this->config->torrentdsBaseUrl() . '/api/block',
            $this->config->torrentdsAdminToken(),
            $kind,
            $value,
        );
    }

    /**
     * Shared POST for both engines (identical request shape). Short-circuits on an
     * empty value or an unconfigured token WITHOUT a network call, then maps the
     * engine's HTTP outcome to a stable status code the controller translates.
     *
     * @return array{ok:bool, status:string, http_status:int}
     */
    private function submit(string $url, string $token, string $kind, string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return self::result(false, 'empty', 0);
        }
        if ($token === '') {
            // The engine gates the control endpoint on a token; without one
            // configured the push can only ever be refused — say so plainly and
            // skip the network call (and never send an empty token).
            return self::result(false, 'unconfigured', 0);
        }

        $body    = http_build_query(['kind' => $kind, 'value' => $value, 'token' => $token]);
        $headerTk = self::headerSafe($token);
        $headers = [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
            'X-Admin-Token: ' . $headerTk,
            'Authorization: Bearer ' . $headerTk,
            'Connection: close',
        ];

        $resp = $this->request($url, $headers, $body);
        if ($resp === null) {
            return self::result(false, 'unreachable', 0);
        }

        $http = $resp['status'];
        if ($http === 401 || $http === 403) {
            return self::result(false, 'forbidden', $http);
        }
        if ($http === 400 || $http === 422) {
            return self::result(false, 'invalid', $http);
        }
        if ($http >= 200 && $http < 300) {
            $dup = self::isDuplicate($resp['body']);
            return self::result(!$dup, $dup ? 'duplicate' : 'added', $http);
        }
        return self::result(false, 'error', $http);
    }

    /**
     * True when a 2xx control response signals the entry already existed. Tolerant
     * of shape: a truthy `duplicate`/`exists` flag, or a `status`/`result` string
     * of "duplicate"/"exists"/"already". Anything else on a 2xx is a fresh add.
     */
    private static function isDuplicate(string $body): bool
    {
        /** @var mixed $data */
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return false;
        }
        foreach (['duplicate', 'exists', 'already'] as $flag) {
            if (!empty($data[$flag])) {
                return true;
            }
        }
        foreach (['status', 'result'] as $key) {
            $v = $data[$key] ?? null;
            if (is_string($v) && in_array(strtolower(trim($v)), ['duplicate', 'exists', 'already', 'already-blocked'], true)) {
                return true;
            }
        }
        return false;
    }

    /** @return array{ok:bool, status:string, http_status:int} */
    private static function result(bool $ok, string $status, int $http): array
    {
        return ['ok' => $ok, 'status' => $status, 'http_status' => $http];
    }

    /** Strip CR/LF so a configured token can never inject an HTTP header. */
    private static function headerSafe(string $token): string
    {
        return str_replace(["\r", "\n"], '', $token);
    }

    // -------------------------------------------------------------------------
    // Transport (bounded timeout + bounded body; mirrors SuiteAdminClient)
    // -------------------------------------------------------------------------

    /**
     * One POST via the stream wrapper. Returns [status, body] or null on any
     * transport failure. `@fopen` keeps a connection-refused warning out of
     * AstrX's error mask (else a 500). Never follows a redirect; caps the body at
     * MAX_BODY under a total wall-clock deadline.
     *
     * @param list<string> $headers
     * @return array{status:int, body:string}|null
     */
    private function request(string $url, array $headers, string $body): ?array
    {
        $timeout = (float) $this->config->timeoutSeconds();
        $ctx     = stream_context_create(['http' => [
            'method'          => 'POST',
            'timeout'         => max(0.1, $timeout),
            'ignore_errors'   => true,   // read the body even on a 4xx/5xx status
            'follow_location' => 0,      // never chase a redirect (SSRF hardening)
            'max_redirects'   => 0,
            'header'          => implode("\r\n", $headers) . "\r\n",
            'content'         => $body,
        ]]);

        $deadline = microtime(true) + max(0.1, $timeout);
        $fp = @fopen($url, 'rb', false, $ctx);
        if ($fp === false) {
            return null;
        }
        $meta = stream_get_meta_data($fp);
        $raw  = self::readCapped($fp, self::MAX_BODY, $deadline);
        fclose($fp);

        $status  = 0;
        $wrapper = $meta['wrapper_data'] ?? null;
        if (is_array($wrapper)) {
            foreach ($wrapper as $line) {
                if (is_string($line) && preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $line, $m) === 1) {
                    $status = (int) $m[1];
                    break;
                }
            }
        }

        return ['status' => $status, 'body' => $raw];
    }

    /**
     * Read up to $max bytes from $fp under a TOTAL wall-clock $deadline, so a
     * slow-drip backend cannot pin the request past its timeout. Returns whatever
     * was read at the deadline (or EOF); a partial body only affects the tolerant
     * duplicate sniff, never the status mapping.
     *
     * @param resource $fp
     */
    private static function readCapped($fp, int $max, float $deadline): string
    {
        stream_set_blocking($fp, false);
        $buf = '';
        while (strlen($buf) <= $max) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                break;
            }
            $read   = [$fp];
            $write  = null;
            $except = null;
            $sec    = (int) $remaining;
            $usec   = (int) (($remaining - $sec) * 1000000);
            $ready  = @stream_select($read, $write, $except, $sec, $usec);
            if ($ready === false || $ready === 0) {
                break;
            }
            $chunk = fread($fp, max(1, min(65536, $max + 1 - strlen($buf))));
            if ($chunk === '' || $chunk === false) {
                if (feof($fp)) {
                    break;
                }
                continue;
            }
            $buf .= $chunk;
        }
        return substr($buf, 0, $max);
    }
}
