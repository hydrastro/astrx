<?php
declare(strict_types=1);

namespace AstrX\Site;

use AstrX\Config\Config;

/**
 * The baseline security headers every HTML/JSON response this site produces
 * carries (CSP, Referrer-Policy, nosniff, X-Frame-Options, Onion-Location).
 */
final class SecurityHeaders
{
    public function __construct(private readonly Config $config) {}

    /**
     * Emit the baseline security headers.
     *
     * ContentManager calls this ONCE at the top of its response phase, and
     * PanicLockdown and ErrorResponder call it for the responses they produce
     * without reaching that point. Every branch below the call site
     * inherits these; a branch that needs a different value overrides that single
     * header rather than opting in, so an early `return` added later cannot ship
     * a document with no CSP the way the JS-fragment branch used to.
     *
     * The canonical site is designed to run with JavaScript OFF, so a strict
     * Content-Security-Policy (default-src 'none') is safe and neutralises any
     * injected script/frame. The CSP is config-driven
     * (ContentManager.content_security_policy) so operators can relax it per
     * deployment without editing code; a hard-coded strict default is used when
     * the config key is absent or empty.
     *
     * No-op if headers have already been sent (e.g. a controller streamed bytes).
     */
    public function emit(SiteFlags $flags): void
    {
        if (headers_sent()) {
            return;
        }

        $defaultCsp = "default-src 'none'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; "
                    . "frame-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'";

        $csp = $this->config->getConfigString(
            'ContentManager',
            'content_security_policy',
            $defaultCsp,
        );
        if ($csp === '') {
            $csp = $defaultCsp;
        }

        header('Content-Security-Policy: ' . $csp);
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        // R13: advertise the canonical .onion on the PRIMARY (no-JS) HTML
        // response. Tor Browser reads Onion-Location from the ordinary page, and
        // AstrX's audience is no-JS — so this path (not only the JS-fragment copy)
        // is where the anti-phishing auto-upgrade must be emitted. Validated to a
        // real .onion URL in SiteFlags::onionPrimary(); '' (unset/invalid) emits nothing.
        $onion = $flags->onionPrimary();
        if ($onion !== '') { header('Onion-Location: ' . $onion); }
    }
}
