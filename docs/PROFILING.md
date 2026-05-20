# AstrX profiling guide

This tree has two complementary profiling layers:

1. **Browser/runtime profiling** through `/en/js/<page>?debug=1`.
2. **PHP/Xdebug profiling** through Xdebug trigger mode.

## Browser/runtime profiling

Open any JS-mode route with debug enabled:

```text
/en/js/admin-banlist?debug=1
```

The runtime overlay shows:

- resource loading source (`templates-js`, inline manifest, runtime fetch)
- template count and compiled-template count
- template compile time
- shell render/parse/install time
- current page fragment fetch time
- response read time
- DOM parse/install time
- total navigation time
- recent Performance API resource entries
- server timing exposed by PHP, when present

You can also enable it persistently from the console:

```js
localStorage.setItem('astrx.debug', '1')
```

Disable:

```js
localStorage.removeItem('astrx.debug')
```

## Expected request pattern for `/js/`

After this patch, the initial `/en/js/<page>` load should not request `templates.json` or `manifest.json` in normal operation.

Expected first load:

```text
GET /en/js/admin-banlist      shell
GET /en/js/templates.js       compiled template bundle
GET /en/js/runtime.js         runtime
GET /en/admin-banlist         content fragment
```

`/en/js/api.json` is only fetched when debug mode is enabled.

`/en/js/templates.json` remains available as a debug/fallback endpoint only. If you still see it in normal logs, the browser is running an old cached shell/runtime or `templates.js` failed to execute.

## PHP/Xdebug profiling

With Xdebug profiler trigger mode enabled, append the trigger to the request you want to profile. Common setups use one of these:

```text
/en/admin-banlist?XDEBUG_TRIGGER=1
/en/js/admin-banlist?XDEBUG_TRIGGER=1
```

or a cookie/session from your browser extension.

Profile both paths separately:

```text
/en/admin-banlist?XDEBUG_TRIGGER=1
/en/js/admin-banlist?XDEBUG_TRIGGER=1&debug=1
```

For JS mode, also profile the fragment request directly:

```text
/en/admin-banlist?XDEBUG_TRIGGER=1
```

with this request header:

```http
X-AstrX-JS-Browser: 1
Accept: text/html
```

That isolates the server-side fragment render from browser/runtime costs.

## Where to look in cachegrind

Start with inclusive time for:

- `AstrX\\ContentManager::init`
- `AstrX\\Page\\PageHandler::*`
- controller `handle()` methods for the page under test
- `AstrX\\Navbar\\NavbarHandler::*`
- `AstrX\\Template\\TemplateEngine::*`
- `AstrX\\Theme\\ThemeService::*`
- `AstrX\\Controller\\JsController::buildTemplateCache`

If `buildTemplateCache` dominates, the template bundle should be written to disk as a real precompiled artifact instead of rebuilt by PHP on each ETag validation.

If `NavbarHandler` dominates, cache navbar entries per user group/locale/page ancestry.

If `TemplateEngine` dominates, cache parsed template tokens or rendered chrome fragments.

If the database dominates, add query logging around PDO or inspect Xdebug call counts for repository methods.

## Server-Timing headers

Responses now emit lightweight timing headers where useful:

```http
Server-Timing: astrx_fragment;dur=12.34
X-AstrX-Elapsed-Ms: 12.34
```

Firefox DevTools can display `Server-Timing` in the Network panel. The JS debug overlay also captures the `Server-Timing` header for the last page/fragment fetch.
