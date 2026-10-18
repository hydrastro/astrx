<?php
declare(strict_types=1);

namespace AstrX\Site;

use AstrX\Http\HttpStatus;
use AstrX\Http\Request;
use AstrX\Page\Page;

/**
 * The operator's panic lockdown: which pages stay reachable while it is on,
 * and the 503 every other request gets. Whether it is on, and who may bypass
 * it (whoever can disarm it, AdminPermission::PANIC), is decided by the
 * caller; see ContentManager.
 */
final class PanicLockdown
{
    public function __construct(private readonly SecurityHeaders $headers) {}

    /**
     * Pages that stay reachable during a panic lockdown — the login page and the
     * assets it needs (captcha) plus the error page — so an admin can still sign
     * in and lift the lockdown. Keyed on file_name (never translated/editable).
     */
    public static function isExempt(Page $page): bool
    {
        // 'twofa' is the second-factor challenge: a TOTP-protected admin lands
        // there mid-login, so it must stay open or they could never sign in to
        // lift the lockdown.
        return in_array($page->fileName, ['login', 'twofa', 'captcha_image', 'captcha_frame', 'error'], true);
    }

    /** Emit the lockdown response (503) — JSON for API callers, a minimal no-JS page otherwise. */
    public function respond(Request $request, SiteFlags $flags): void
    {
        http_response_code(HttpStatus::SERVICE_UNAVAILABLE->value);

        if ($request->isApi()) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
                header('Retry-After: 3600');
            }
            echo json_encode([
                'ok'     => false,
                'status' => 503,
                'error'  => ['id' => 'astrx.panic/locked', 'message' => 'Service temporarily locked.'],
            ], JSON_UNESCAPED_SLASHES);
            return;
        }

        $this->headers->emit($flags);
        if (!headers_sent()) {
            header('Retry-After: 3600');
        }
        $safe = htmlspecialchars($flags->panicMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head><meta charset="UTF-8"><meta name="robots" content="noindex,nofollow"><title>Temporarily unavailable</title></head>
        <body>
          <h1>Temporarily unavailable</h1>
          <p>{$safe}</p>
        </body>
        </html>
        HTML;
    }
}
