<?php

declare(strict_types = 1);

namespace AstrX\Csrf;

use AstrX\Config\InjectConfig;
use AstrX\Csrf\Diagnostic\CsrfTokenExpiredDiagnostic;
use AstrX\Csrf\Diagnostic\CsrfTokenMismatchDiagnostic;
use AstrX\Csrf\Diagnostic\CsrfTokenMissingDiagnostic;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\Diagnostics;
use AstrX\Result\Result;

/**
 * Session-backed CSRF token handler.
 * Tokens are scoped to a form ID so multiple forms open in parallel
 * (e.g. login in one tab, register in another) never interfere with each other.
 * Storage layout in $_SESSION:
 *   CSRF_<formId> => ['token' => '<hex>', 'expires_at' => <unix timestamp>]
 * Tokens are single-use: a successful verify() immediately removes the entry.
 * A failed verify() leaves the entry intact so the controller can re-present
 * the form with the same token still valid (until it naturally expires).
 * Usage — generating a token for a form:
 *   $token = $this->csrf->generate('login');
 *   // Pass $token to the template as {{csrf_token}}
 * Usage — verifying on POST:
 *   $result = $this->csrf->verify('login', $request->post('_csrf') ?? '');
 *   if (!$result->isOk()) {
 *       $result->drainTo($this->collector);
 *       // Re-render form...
 *   }
 */
final class CsrfHandler
{
    // -------------------------------------------------------------------------
    // Diagnostic policy
    // -------------------------------------------------------------------------

    public const string ID_TOKEN_MISSING = 'astrx.csrf/token_missing';
    public const string ID_TOKEN_MISMATCH = 'astrx.csrf/token_mismatch';
    public const string ID_TOKEN_EXPIRED = 'astrx.csrf/token_expired';
    public const DiagnosticLevel LVL_TOKEN_MISSING = DiagnosticLevel::WARNING;
    public const DiagnosticLevel LVL_TOKEN_MISMATCH = DiagnosticLevel::WARNING;
    public const DiagnosticLevel LVL_TOKEN_EXPIRED = DiagnosticLevel::NOTICE;
    // -------------------------------------------------------------------------
    // Session key prefix
    // -------------------------------------------------------------------------

    private const SESSION_PREFIX = 'CSRF_';
    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    /** Token lifetime in seconds. Default: 5 minutes. */
    private int $ttl = 300;
    /** Entropy in bytes. 32 bytes = 64-char hex token. */
    private int $tokenBytes = 32;

    #[InjectConfig('csrf_ttl')]
    public function setTtl(int $ttl)
    : void {
        $this->ttl = max(1, $ttl);
    }

    #[InjectConfig('csrf_token_bytes')]
    public function setTokenBytes(int $bytes)
    : void {
        $this->tokenBytes = max(16, $bytes);
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Generate a fresh CSRF token for the given form, store it in the session,
     * and return the hex string to be embedded in the form.
     * Calling generate() a second time for the same formId replaces the
     * previous token — the old one is silently invalidated.
     */
    public function generate(string $formId)
    : string {
        $token = bin2hex(random_bytes(max(1, $this->tokenBytes)));

        $_SESSION[self::SESSION_PREFIX . $formId] = [
            'token' => $token,
            'expires_at' => time() + $this->ttl,
        ];

        return $token;
    }

    /**
     * Verify a submitted CSRF token against the session.
     * Returns Result::ok(true) on success and removes the token (single-use).
     * Returns Result::err on failure; the session entry is NOT removed so the
     * controller can re-render the form and let the user try again.
     * Failure diagnostics:
     *   CsrfTokenMissingDiagnostic  — no token in POST / empty string submitted
     *   CsrfTokenMismatchDiagnostic — token present but does not match session
     *   CsrfTokenExpiredDiagnostic  — token matched but past expiry time
     * @return Result<bool>
     */
    public function verify(string $formId, string $submitted)
    : Result {
        // Missing: empty submission or no session entry at all
        if ($submitted === '' || !$this->hasEntry($formId)) {
            return Result::err(
                false,
                Diagnostics::of(
                    new CsrfTokenMissingDiagnostic(
                        self::ID_TOKEN_MISSING,
                        self::LVL_TOKEN_MISSING,
                        $formId,
                    ),
                )
            );
        }

        $entry = $this->getEntry($formId);

        // Mismatch: constant-time comparison to prevent timing attacks
        if (!hash_equals($entry['token'], $submitted)) {
            return Result::err(
                false,
                Diagnostics::of(
                    new CsrfTokenMismatchDiagnostic(
                        self::ID_TOKEN_MISMATCH,
                        self::LVL_TOKEN_MISMATCH,
                        $formId,
                    ),
                )
            );
        }

        // Expired: token matched but window has passed
        if (time() > $entry['expires_at']) {
            return Result::err(
                false,
                Diagnostics::of(
                    new CsrfTokenExpiredDiagnostic(
                        self::ID_TOKEN_EXPIRED,
                        self::LVL_TOKEN_EXPIRED,
                        $formId,
                        $entry['expires_at'],
                    ),
                )
            );
        }

        // Success: consume the token
        $this->removeEntry($formId);

        return Result::ok(true);
    }

    /**
     * Explicitly discard a token without verifying it.
     * Useful when a form submission succeeds via other means and you want
     * to ensure the token cannot be replayed.
     */
    public function forget(string $formId)
    : void {
        $this->removeEntry($formId);
    }

    /**
     * Check whether a (possibly expired) token currently exists in the session
     * for this form. Useful for pre-flight checks in templates.
     */
    public function has(string $formId)
    : bool {
        return $this->hasEntry($formId);
    }

    // -------------------------------------------------------------------------
    // Session helpers
    // -------------------------------------------------------------------------

    private function hasEntry(string $formId)
    : bool {
        return isset($_SESSION[self::SESSION_PREFIX . $formId]) &&
               is_array($_SESSION[self::SESSION_PREFIX . $formId]);
    }

    /** @return array{token: string, expires_at: int} */
    private function getEntry(string $formId)
    : array {
        /** @var array{token: string, expires_at: int} */
        return $_SESSION[self::SESSION_PREFIX . $formId];
    }

    private function removeEntry(string $formId)
    : void {
        unset($_SESSION[self::SESSION_PREFIX . $formId]);
    }
}
