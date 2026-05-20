<?php
declare(strict_types=1);

namespace AstrX\Api;

/**
 * Declares the visibility scope of a value set on the template context.
 *
 * The default scope is WEB_ONLY: any value a controller sets without
 * specifying a scope is treated as web-page-only and is NOT exposed to API
 * callers. This is the secure-by-default rule — accidentally leaking data
 * via the API requires explicit opt-in at the set() call site.
 *
 * SHARED is the most common case for API-enabled endpoints: the value is
 * safe to render in the web template AND safe to expose to API callers.
 *
 * API_PUBLIC is for values that should appear in the JSON response but not
 * in the rendered HTML (e.g. structured metadata that the HTML template
 * doesn\'t use).
 *
 * API_ADMIN is for values that only admin-permissioned API callers see.
 * The JsonRenderer omits these from the response unless the request was
 * authenticated as an admin user.
 */
enum ContextScope: string
{
    /** Default. Visible only in the rendered HTML. Never exposed via API. */
    case WEB_ONLY   = 'web_only';

    /** Visible both in the HTML and to all API callers. */
    case SHARED     = 'shared';

    /** Visible only to API callers, regardless of permission. */
    case API_PUBLIC = 'api_public';

    /** Visible only to API callers authenticated as admin. */
    case API_ADMIN  = 'api_admin';

    /**
     * Returns true if a value at this scope should be included in the
     * JSON envelope, given whether the caller is admin-authenticated.
     */
    public function visibleToApi(bool $isAdmin): bool
    {
        return match ($this) {
            self::WEB_ONLY   => false,
            self::SHARED     => true,
            self::API_PUBLIC => true,
            self::API_ADMIN  => $isAdmin,
        };
    }
}
