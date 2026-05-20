# AstrX HTTP API

The AstrX API exposes existing pages as JSON endpoints. Every API endpoint is a regular page that has been explicitly opted in via the `api_enabled` flag. The same controller code serves both the web view and the API — no parallel codepaths, no duplicated logic, no separate "API controllers" to keep in sync.

## URL format

Two routing modes are supported, mirroring the framework's web routing:

**Rewrite mode** (default):
```
/<locale>/api/<page-slug>[/<tail>]
```
Examples:
```
GET  /en/api/user-profile/123
POST /en/api/comment-new
```

**Query-string mode**:
```
/?lang=<locale>&page=<page>&api=1
```

Both forms route to the same controller as their non-API counterpart.

### JS runtime API index

The experimental `/js/` browser exposes a small same-origin API index for debugging and client discovery:

```
/<locale>/js/api.json
```

Example:

```
GET /en/js/api.json
```

It returns the locale API base, query-mode example, and every page currently marked with `page.api_enabled = 1`. This endpoint is not a data API by itself; it is a machine-readable index of enabled page APIs. The actual page APIs remain under `/<locale>/api/<page-slug>`.

---

## Opt-in per page

Pages are NOT exposed via the API by default. Each row in the `page` table has an `api_enabled TINYINT NOT NULL DEFAULT 0` column. A request to `/api/<page>` for a page where `api_enabled = 0` returns `404` — we do not reveal whether the page exists.

To enable a page for API access:

```sql
UPDATE `page` SET `api_enabled` = 1 WHERE `url_id` = 'WORDING_USER_PROFILE';
```

Until a future admin UI is added, this is a manual operation. Most pages should stay disabled — only opt in endpoints whose context has been audited for safe exposure (see [Context scopes](#context-scopes) below).

---

## Authentication

Three modes, in order of precedence:

### 1. API key (Bearer token)

The primary auth mechanism for non-browser clients. Include an `Authorization` header:

```
Authorization: Bearer astrx_3f8c9e2d1a7b6c5d4e9f8a1b2c3d4e5f6a7b8c9d0e1f2a3b
```

Keys are `astrx_` followed by 48 hex characters (192 bits of entropy). They are scoped to a single user account; the request acts as if that user had logged in. Permissions, role checks, and Policy classes apply identically to web sessions.

### 2. Session cookie

If the request carries a valid session cookie (e.g. a logged-in user opens `/api/...` in their browser), authentication uses the existing session. Useful for fetch() calls from a same-origin SPA.

### 3. Anonymous

If neither bearer token nor session is present, the request is anonymous (guest role). Most write endpoints will return 403.

---

## Creating an API key

Until the user-facing UI ships, keys are created directly:

```sql
-- Get the user's hex ID
SELECT LOWER(HEX(id)) FROM user WHERE username = 'alice';
-- Insert a key (raw key is 'astrx_<48 hex chars>', sha256 it for storage):
-- This example uses MariaDB's SHA2 function; in PHP use hash('sha256', $raw).
INSERT INTO api_key (id, user_id, label, key_hash, created_at)
VALUES (
    UNHEX(REPLACE(UUID(), '-', '')),
    UNHEX('<alice-hex-id>'),
    'CLI access',
    SHA2('astrx_<your-48-hex-chars>', 256),
    NOW()
);
```

PHP equivalent (for scripted provisioning):

```php
$service = $injector->get(ApiKeyService::class);
$result = $service->create($hexUserId, label: 'My script', expiresAtTs: null);
$rawKey = $result->unwrap();  // shown ONCE — save it
```

---

## Response format

### Success

```json
{
  "ok": true,
  "status": 200,
  "data": {
    "username": "alice",
    "display_name": "Alice",
    "comment_count": 17
  },
  "html": "<div id=\"main\">...</div>",
  "meta": {
    "locale": "en",
    "page": "WORDING_USER_PROFILE",
    "diagnostics": { "total": 0, "visible": 0, "hidden": 0 }
  },
  "diagnostics": []
}
```

### Failure

```json
{
  "ok": false,
  "status": 403,
  "error": {
    "id": "astrx.auth/forbidden",
    "level": "error",
    "level_value": 4,
    "message": "Forbidden"
  },
  "meta": {
    "locale": "en",
    "page": "WORDING_ADMIN",
    "diagnostics": { "total": 1, "visible": 1, "hidden": 0 }
  },
  "diagnostics": [
    {
      "id": "astrx.auth/forbidden",
      "level": "error",
      "level_value": 4,
      "message": "Forbidden"
    }
  ]
}
```

The dominant effective diagnostic level determines whether `ok` is true. `NOTICE` and `WARNING` remain successful responses and are surfaced as notes. `ERROR` and above make the response a failure. The status mapper prefers explicit diagnostic context (`http_status` or `status`) when present, then applies conservative domain defaults: CSRF/auth errors become `403`, missing/not-found style errors become `404`, user/comment validation errors become `422`, and framework/runtime errors become `500`/`503`.

Each diagnostic object is serialized from the same typed `DiagnosticInterface` used by the HTML pipeline:

```json
{
  "id": "astrx.template/undefined_token_argument",
  "level": "notice",
  "level_value": 2,
  "message": "[FALLBACK:NOTICE] astrx.template/undefined_token_argument",
  "context": { "token": "some_template_key" }
}
```

The API renderer does not require diagnostic classes to implement a duplicate `context()` method. Public zero-argument accessors such as `token()`, `file()`, `message()`, `detail()`, `captchaId()`, and `getClassName()` are reflected into the `context` object. Diagnostic visibility and effective level overrides follow the same `DiagnosticVisibilityChecker` used by the normal message bar; hidden diagnostics still affect the status code but their details are not exposed.

---

## Including / excluding rendered HTML

By default every successful response includes a `html` field with the fully-rendered template — same HTML the web view would have shown. This is what makes AstrX usable as a headless CMS for a future client-rendered app: one API call returns both structured data and pre-rendered HTML in a single round trip.

To exclude the HTML (e.g. for pure-data clients that don't need it):

```
GET /en/api/user-profile?html=0
```

The response then has no `html` field. The `data` field is unaffected.

---

## Context scopes

The `data` field of API responses is filtered by `ContextScope`. This is a security boundary — controllers must explicitly opt context values into the API.

| Scope         | Web HTML | API response                | Use for                                          |
|---------------|----------|-----------------------------|--------------------------------------------------|
| `WEB_ONLY`    | yes      | **no** — never appears      | Default. Anything not explicitly tagged.         |
| `SHARED`      | yes      | yes                         | Most safe-to-expose values.                      |
| `API_PUBLIC`  | no       | yes                         | Structured data the HTML template doesn't use.   |
| `API_ADMIN`   | no       | yes — only if caller is admin | Internal diagnostics, debug data, audit fields. |

Controllers tag values at the `set()` call site:

```php
// Default — WEB_ONLY. Will not appear in the API response.
$this->ctx->set('rendered_avatar_html', $html);

// Shared between HTML and API.
$this->ctx->set('username', $name, ContextScope::SHARED);
// Or the convenience shortcut:
$this->ctx->setShared('username', $name);

// API-only (HTML template doesn't reference it).
$this->ctx->set('api_meta', $payload, ContextScope::API_PUBLIC);

// Admin-only API exposure.
$this->ctx->set('audit_trail', $log, ContextScope::API_ADMIN);
```

This means **enabling `api_enabled = 1` on a page does not automatically expose its data**. Each context value the controller sets needs a deliberate `ContextScope::SHARED` (or stronger) to appear in the JSON. Auditing a controller for API readiness is the same as auditing every `$this->ctx->set(...)` call.

---

## CSRF on the API

POST/PUT/DELETE-style page actions still use the same controller-level CSRF checks as the normal web forms. There is no universal API CSRF token because AstrX tokens are form-scoped (`login`, `register`, `admin_themes`, etc.) and several pages contain multiple independent forms.

For same-origin browser clients, the safest pattern is to request the endpoint with rendered HTML enabled, read the relevant form's hidden `_csrf` and `prg_id` fields, then submit them back exactly as the normal page would. For bearer-token automation, prefer read-only `GET` endpoints until a controller has an explicit non-browser API contract.

The `meta.csrf_token` field in the JSON envelope is reserved for future form-specific API helpers and is omitted unless a controller passes a token explicitly.

---

## Rate limiting

Not implemented in this release. Apply nginx-level rate limiting to `/api/` as a stop-gap:

```nginx
limit_req_zone $binary_remote_addr zone=astrx_api:10m rate=10r/s;

location /en/api/ {
    limit_req zone=astrx_api burst=20 nodelay;
    try_files $uri $uri/ /index.php?$args;
}
```

---

## Versioning

Not implemented in this release. The URL is `/api/`, not `/api/v1/`. When a breaking change is needed, the path becomes `/api/v2/` and the previous version remains under `/api/` (which is `/api/v1/` by convention) until deprecation.

---

## Errors you might see

| ID                          | When                                                | Status |
|-----------------------------|-----------------------------------------------------|--------|
| `astrx.api/not_enabled`     | URL matches a page that has `api_enabled = 0`        | 404    |
| `astrx.api/key_create_failed` | DB error while creating a new key                 | 500    |
| `astrx.csrf/missing_token`  | POST without CSRF                                    | 400    |
| `astrx.auth/forbidden`      | Authenticated but lacks permission                   | 403    |
| `astrx.i18n/missing_translation` | A lang key was not found — NOTICE only           | 200    |

Most are non-fatal; the response still carries `"ok": true` if the dominant diagnostic level is `NOTICE` or `WARNING`.

---

## Reference example: profile endpoint

The user profile page is the first endpoint shipped with API access enabled. It demonstrates the full opt-in flow.

### Request

```bash
# Anonymous, full response with HTML
curl http://localhost/en/api/profile/alice

# Same, data only
curl 'http://localhost/en/api/profile/alice?html=0'

# Authenticated as a specific user (bearer token from settings page)
curl -H 'Authorization: Bearer astrx_<your-key>' \
     http://localhost/en/api/profile/alice
```

### Response

```json
{
  "ok": true,
  "status": 200,
  "data": {
    "profile_not_found":   false,
    "profile_id":          "f5a2c8...e1",
    "profile_username":    "alice",
    "profile_display_name":"Alice",
    "profile_group":       "User",
    "profile_verified":    true,
    "profile_avatar_src":  "/avatar/f5a2c8...e1.png",
    "profile_has_avatar":  true,
    "profile_joined":      "2025-09-04 14:22:01"
  },
  "html": "<h2>Alice</h2>...",
  "meta": { "locale": "en", "page": "WORDING_PROFILE" },
  "diagnostics": []
}
```

### Why only these fields?

The `ProfileController` sets ~15 context variables. Only the 8 above appear in `data` because they are explicitly tagged with `ContextScope::SHARED`. The rest — `profile_is_own` (UI button state), `profile_settings_url` (a relative URL the HTML uses), translation keys for headings — stay `WEB_ONLY` (the default scope) and never leak to the API.

To audit a controller for API readiness, walk every `$this->ctx->set(...)` call and decide:

- **Tag as `SHARED`** if the value is safe for any caller to see AND the HTML uses it (the common case).
- **Tag as `API_PUBLIC`** if the value should be in JSON but the HTML doesn't render it.
- **Tag as `API_ADMIN`** if only admin callers should see it (e.g. internal IDs, audit timestamps).
- **Leave default (`WEB_ONLY`)** if the value is UI state, an i18n label, or anything a caller-side renderer wouldn't need.

### Enabling another endpoint

```sql
UPDATE `page` SET `api_enabled` = 1 WHERE `url_id` = 'WORDING_YOUR_PAGE';
```

Before doing this on any new page, walk its controller and tag the fields. **A page with `api_enabled = 1` but no tagged context produces `"data": {}` in the response** — it never accidentally leaks `WEB_ONLY` values, but it also isn't useful until you tag at least one field.

---

## Quick API checks

```bash
# List enabled page APIs known to the JS runtime
curl http://localhost/en/js/api.json

# Fetch a data-only page API response
curl 'http://localhost/en/api/profile/alice?html=0'

# Fetch the same endpoint with rendered HTML included
curl http://localhost/en/api/profile/alice
```

A `404` with `astrx.api/not_enabled` means the route resolved but the page has not been opted into API access. An empty `data` object with `ok: true` means the page is enabled but the controller has not tagged any context values as `SHARED`, `API_PUBLIC`, or `API_ADMIN`.

---

## Managing your API keys

Logged-in users can manage their own keys from the user settings page (`/{locale}/settings`). The page has an "API Keys" section showing:

- A table of existing keys with label, creation date, last-used time, and a "Revoke" button.
- A form to create a new key by giving it a label.
- The **raw key value is shown exactly once** on creation, in a callout box. After you navigate away, it cannot be retrieved — only revoked.

The key value is `astrx_` followed by 48 hex characters (192 bits of entropy). Use it as a bearer token:

```bash
curl -H 'Authorization: Bearer astrx_3f8c9e2d1a7b6c5d4e9f8a1b2c3d4e5f6a7b8c9d0e1f2a3b' \
     http://localhost/en/api/profile
```

The request authenticates as the user who created the key, with their full permissions. Gate and PolicyInterface checks apply identically to web sessions.

### Scripted provisioning

For headless setups (e.g. first-time install), call `ApiKeyService` directly:

```php
$service = $injector->createClass(ApiKeyService::class)->unwrap();
$result  = $service->create($hexUserId, label: 'My script');
$rawKey  = $result->unwrap();   // shown ONCE — save it
file_put_contents('/etc/myapp/api.key', $rawKey, LOCK_EX);
```

Or insert directly into the DB:

```sql
INSERT INTO api_key (id, user_id, label, key_hash, created_at)
VALUES (
    UNHEX(REPLACE(UUID(), '-', '')),
    UNHEX('<alice-hex-id>'),
    'CLI access',
    SHA2('astrx_<your-48-hex-chars>', 256),
    NOW()
);
```
