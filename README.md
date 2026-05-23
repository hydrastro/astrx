# AstrX

AstrX is a small PHP framework/CMS that keeps server-rendered, JavaScript-free pages as the canonical mode while also exposing two optional acceleration/benchmark paths:

- **Normal mode**: `/en/main`, `/en/user`, `/en/admin-*`
- **JS browser mode**: `/en/js/main`, `/en/js/user`, `/en/js/admin-*`
- **Compiled benchmark mode**: `/compile/en/main`, `/compile/en/user`, `/compile/en/admin-*`
- **Compiled JS benchmark mode**: `/compile/en/js/main`, `/compile/en/js/admin-*`

The normal PHP path remains the source of truth. JS mode and compiled mode are opt-in experiments for profiling and production-readiness work.

## Repository layout

```text
public/                 Web root and front controllers
public/index.php        Normal front controller
public/compiled.php     Compiled front controller without URL prefix
public/compile/         /compile benchmark front controller
src/                    Framework source
resources/config/       Local runtime configuration
resources/template/     Server templates and themes
setup/                  Fresh database bootstrap SQL
tools/                  Build, verification, profiling, and cache tools
docs/                   API, compiled-build, and profiling notes
docker/                 Local Docker/Nginx/PHP/MariaDB setup
```

Generated/runtime files are intentionally not part of the clean source state:

```text
build/astrx.compiled.php
resources/template/cache/*
xdebug-profiles/*
*.patch
astrx_full_repo_*.zip
```

## Quick start

```bash
mkdir -p build resources/template/cache
php tools/warm-template-cache.php --clear
php tools/compile.php
php tools/verify-compiled.php

docker compose up -d --build
```

Open:

```text
http://localhost/en/main
```

## Required Docker mounts

`/compile` mode requires the generated bundle to be visible inside PHP-FPM. The `phpfpm` service must mount:

```yaml
- ./build:/app/build
```

The local Nginx config should also be mounted during development so route changes apply without rebuilding the image:

```yaml
- ./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro
```

After changing `docker/nginx/default.conf`, run:

```bash
docker compose up -d --force-recreate nginx phpfpm
```

## Normal mode

Normal mode uses `public/index.php` and filesystem source loading:

```text
/en/main
/en/user
/en/admin-navbar
```

Request flow:

```text
Nginx → public/index.php → src/bootstrap.php → ContentManager → controller/template → HTML
```

This is the stable, JS-less framework path.

## JS browser mode

JS mode lives under `/en/js/...`:

```text
/en/js/main
/en/js/user
/en/js/admin-navbar
```

It serves a small shell and runtime, then browses normal PHP pages through server-side fragments. The runtime keeps navigation inside JS mode and preserves forms/login redirects.

Generated JS endpoints:

```text
/en/js/runtime.js
/en/js/templates.js
/en/js/templates.json   fallback/debug only
/en/js/manifest.json    debug/tooling
/en/js/api.json         debug/tooling
```

Debug overlay:

```text
/en/js/main?debug=1
```

## API mode

API routes are opt-in per page using `page.api_enabled`:

```text
/en/api/main
/en/api/main?html=0
```

Controllers expose API-safe data only through scoped template context values. Ordinary web-only template values are not automatically serialized.

See [`docs/API.md`](docs/API.md).

## Compiled benchmark mode

Build the bundle:

```bash
php tools/warm-template-cache.php --clear
php tools/compile.php
php tools/verify-compiled.php
```

Compiled mode uses one generated PHP bundle:

```text
build/astrx.compiled.php
```

Benchmark URLs:

```text
/compile
/compile/en/main
/compile/en/user
/compile/en/admin-navbar
/compile/en/js/main
```

Request flow:

```text
Nginx → public/compile/index.php → build/astrx.compiled.php → ContentManager
```

`/compile` is a benchmark prefix. Internally the framework still routes `/en/main`; generated internal links are prefixed back to `/compile/en/main` so navigation stays in compiled mode.

If `/compile/en/...` returns the normal 404 page or links do not start with `/compile`, Nginx is not using the updated `/compile` location. Run:

```bash
docker compose exec nginx nginx -T | grep -A25 'location = /compile'
docker compose exec phpfpm ls -lah /app/build/astrx.compiled.php
```

## Template cache

Warm all server-side templates before benchmarking:

```bash
php tools/warm-template-cache.php --clear
```

This writes compiled template classes and an index under:

```text
resources/template/cache/
```

`php tools/compile.php` also warms the template cache.

## Profiling

Use Xdebug profiling when comparing modes. For admin pages, pass the browser session cookie so curl profiles the logged-in path instead of guest redirects.

```bash
COOKIE_HEADER='PHPSESSID=your_cookie_here' \
HEAVY_ROUTE=admin-config-access \
LOAD_FACTOR=3 \
./tools/profile-modes.sh
```

The script profiles:

```text
normal
compiled
js_shell
js_fragment
compiled_js_shell
compiled_js_fragment
```

Outputs go to:

```text
xdebug-profiles/modes-YYYYMMDD-HHMMSS/
```

Open with:

```bash
kcachegrind xdebug-profiles/modes-*/*/cachegrind.out.*
```

See [`docs/PROFILING.md`](docs/PROFILING.md).

## Useful commands

```bash
# Syntax check framework PHP
find src public tools resources/template/themes -name '*.php' -type f -print0 | xargs -0 -n1 php -l

# Rebuild compiled benchmark mode
php tools/warm-template-cache.php --clear
php tools/compile.php
php tools/verify-compiled.php

# Check Docker can see the bundle
docker compose exec phpfpm ls -lah /app/build/astrx.compiled.php

# Show active Nginx config
docker compose exec nginx nginx -T
```

## Development rule of thumb

Keep these concerns separate:

```text
normal mode      correctness and baseline behavior
JS mode          browser/runtime experiment
compiled mode    PHP boot-path benchmark
API mode         explicit JSON/data surface
```

When optimizing, compare the same page in all relevant modes before changing architecture.
