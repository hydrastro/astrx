<?php
declare(strict_types=1);

namespace AstrX\Site;

use AstrX\Config\Config;
use AstrX\Http\Request;
use AstrX\Injector\Injector;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Session\SecureSessionHandler;
use AstrX\User\UserGroup;

/**
 * Starts this request's PHP session on the database-backed, encrypted
 * SecureSessionHandler, with the site's rules:
 *
 *   - bearer (API-key) requests are STATELESS: any inbound session cookie or
 *     URL sid is ignored and no Set-Cookie is emitted;
 *   - in cookieless mode a URL sid is adopted only if the store knows it;
 *   - the cookie is HttpOnly, SameSite=Lax, and Secure only over real HTTPS
 *     (X-Forwarded-Proto is honoured only behind a declared trusted proxy);
 *   - strict mode is forced, so an unknown id is never adopted;
 *   - the id is regenerated on privilege change and on a per-group interval,
 *     keeping an active remember-me expiry.
 */
final class SessionStarter
{
    public function __construct(
        private readonly Injector             $injector,
        private readonly Config               $config,
        private readonly DiagnosticsCollector $collector,
    ) {}

    /**
     * @param string|null $sidCandidate a session id read from the URL (cookieless mode)
     * @param bool        $bearerPresent the request carries an API-key bearer token
     * @return bool false when the session handler could not be built
     */
    public function start(Request $request, ?string $sidCandidate, bool $bearerPresent): bool
    {
        $sessionUseCookies = $this->config->getConfigBool('Session', 'use_cookies', true);

        $sessionResult = $this->injector->createClass(SecureSessionHandler::class)
            ->drainTo($this->collector);

        if (!$sessionResult->isOk()) {
            return false;
        }

        /** @var SecureSessionHandler $sessionHandler */
        $sessionHandler = $sessionResult->unwrap();

        session_set_save_handler($sessionHandler, true);

        // Bearer/API requests must be STATELESS: never mint or reuse a browser
        // cookie session for them. Otherwise a cookie-jar client that sends the
        // key once keeps an authenticated session cookie that (a) then works
        // WITHOUT the key and (b) survives key revocation/expiry — defeating the
        // credential's primary control. Detect the bearer before session_start,
        // ignore any inbound session cookie (so a bearer call can't hijack or
        // overwrite the caller's own browser session), and suppress Set-Cookie
        // via the ini flags set just below.
        if ($bearerPresent) {
            unset($_COOKIE[session_name()]);
        }

        // R10: exclude bearer requests from URL-sid adoption too. In cookieless
        // mode the sid rides in the URL; without this guard a bearer call would
        // adopt a victim-supplied sid and loginFromApiKey() would then overwrite
        // THAT session with the API-key identity — poisoning the victim's session.
        // A bearer request must run on a throwaway session (mirrors the cookie
        // unset above).
        if (!$sessionUseCookies && $sidCandidate !== null && !$bearerPresent) {
            if ($sessionHandler->validateId($sidCandidate)) {
                session_id($sidCandidate);
            }
        }

        // Harden session cookie: HttpOnly (no JS access), SameSite=Lax (CSRF
        // mitigation). The Secure flag is set only when the request is over HTTPS
        // so that local HTTP development still works. In production behind a TLS
        // terminator, $_SERVER['HTTPS'] is set to 'on' by the web server or by the
        // X-Forwarded-Proto header (if your proxy sets it).
        // X-Forwarded-Proto is client-settable, so only honour it when the operator
        // has declared a trusted TLS-terminating proxy. Default off → the Secure
        // flag follows the real connection only. This matters on a plain-HTTP
        // hidden service, where a spoofed header would otherwise set Secure and
        // stop the session cookie being sent at all.
        $trustFwdProto = $this->config->getConfig('ContentManager', 'trust_forwarded_proto', false) === true;
        $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || ($trustFwdProto
                    && isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
                    && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        $existingParams = session_get_cookie_params();
        session_set_cookie_params([
            'lifetime' => $existingParams['lifetime'],
            /** @phpstan-ignore notIdentical.alwaysTrue */
            'path'     => $existingParams['path'] !== '' ? $existingParams['path'] : '/',
            'domain'   => $existingParams['domain'],
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        if (session_status() === PHP_SESSION_NONE) {
            // Enforce strict session mode regardless of php.ini so an
            // uninitialised/attacker-supplied session ID is never adopted.
            // This activates SecureSessionHandler::validateId() on the incoming ID.
            ini_set('session.use_strict_mode', '1');
            // Genuinely honour a cookieless deployment (php.ini ships
            // use_cookies=1, so the mode was silently still emitting cookies), and
            // force cookieless for stateless bearer requests — no Set-Cookie is
            // emitted in either case.
            if (!$sessionUseCookies || $bearerPresent) {
                ini_set('session.use_cookies', '0');
                ini_set('session.use_only_cookies', '0');
            }
            session_start();
        }

        // ── Session ID regeneration ───────────────────────────────────────────
        // Regenerate the session ID on privilege changes (login/logout/role change)
        // and on a time-based interval configurable per UserGroup.
        $this->maybeRegenerateSession($sessionHandler);

        return true;
    }

    private function maybeRegenerateSession(SecureSessionHandler $handler): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $forceRegen = ($_SESSION['_regen_force'] ?? false) === true;
        unset($_SESSION['_regen_force']);

        if (!$forceRegen && !$this->isTimeBasedRegenDue()) {
            return;
        }

        $oldSid      = (string) session_id();
        $oldHashedId = $handler->hashIdPublic($oldSid);

        // Keep the old row so in-flight requests can still find it.
        session_regenerate_id(false);

        $newSid      = (string) session_id();
        $newHashedId = $handler->hashIdPublic($newSid);

        $handler->markReplaced($oldHashedId, $newHashedId);

        $_SESSION['_regen_at'] = time();

        // Preserve an active remember-me expiry across regeneration — otherwise the
        // regenerated cookie reverts to a session-lifetime cookie and remember-me
        // is silently lost on the request after login (R3-13).
        $rememberUntil = $_SESSION['_remember_until'] ?? 0;
        if (is_int($rememberUntil) && $rememberUntil > time()) {
            $params = session_get_cookie_params();
            setcookie((string) session_name(), (string) session_id(), [
                'expires'  => $rememberUntil,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }

    private function isTimeBasedRegenDue(): bool
    {
        $loggedIn = ($_SESSION['logged_in'] ?? false) === true;
        $groupKey = 'GUEST';

        if ($loggedIn) {
            $userData = $_SESSION['user'] ?? null;
            if (is_array($userData)) {
                /** @var array<string,mixed> $userData */
                $typeRaw  = $userData['type'] ?? UserGroup::GUEST->value;
                $type     = is_int($typeRaw) ? $typeRaw
                    : (is_numeric($typeRaw) ? (int)(string)$typeRaw : UserGroup::GUEST->value);
                $group    = UserGroup::tryFrom($type) ?? UserGroup::GUEST;
                $groupKey = $group->name;
            }
        }

        /** @var mixed $rawConfig */
        $rawConfig = $this->config->getConfig('Session', 'regenerate_interval', []);
        if (!is_array($rawConfig)) {
            return false;
        }
        /** @var array<string,mixed> $regenConfig */
        $regenConfig = $rawConfig;

        $rawInterval = $regenConfig[$groupKey] ?? $regenConfig['default'] ?? 0;
        $interval    = is_int($rawInterval) ? $rawInterval
            : (is_numeric($rawInterval) ? (int)(string)$rawInterval : 0);

        if ($interval <= 0) {
            return false;
        }

        $lastRaw   = $_SESSION['_regen_at'] ?? 0;
        $lastRegen = is_int($lastRaw) ? $lastRaw
            : (is_numeric($lastRaw) ? (int)(string)$lastRaw : 0);

        return (time() - $lastRegen) >= $interval;
    }
}
