# Getting started

This guide is for the bundled application in the current repository. A separately installable PHP core package is planned, not yet available.

## Requirements

Use PHP 8.4 or newer with PDO, PDO MySQL, OpenSSL, GD and mbstring, plus a compatible MariaDB/MySQL instance. Optional features may require more: HTML email handling uses DOM facilities, and mail integration needs its configured mail service. The image/container configurations and enabled modules determine the full platform requirements.

The portable PHP tests also use PDO SQLite. Rust services are built separately with Cargo; their manifests declare Rust 1.89 as the minimum and the development toolchain selects stable. Network-enabled binaries require the `net` feature and its dependencies. Validate the declared minimum toolchain separately when changing Rust code.

## Obtain the source

```sh
git clone https://github.com/hydrastro/astrx.git
cd astrx
php tools/install.php --help
```

Use a fresh database for an initial trial. Do not run an installer against an existing deployment without first understanding its schema/migration behavior and making a restorable backup.

## Local application installation

1. Prepare the database and an application account with access to that database. Use the default `content_manager` database name for the initial trial; some shipped SQL assumes it.
2. Make `resources/config/`, `resources/template/cache/` and `resources/avatar/` writable by the identity performing the relevant setup/runtime operation. Other enabled modules need their own storage paths. Keep source read-only where practical and do not make the entire checkout world-writable.
3. Run the installer interactively. Its prompts avoid putting passwords directly into shell history:

   ```sh
   php tools/install.php
   ```

4. Use an administrator password of at least 12 Unicode characters, at most 4096 UTF-8 bytes. Both installers and account operations enforce the same bounds.
5. Confirm the generated database and session configuration matches the intended deployment. Protect and back up the installation secret: encrypted account/session state depends on it.
6. Configure the web server with its document root at `public/`. For rewritten routes, an nginx location typically uses:

   ```nginx
   location / {
       try_files $uri $uri/ /index.php$is_args$args;
   }
   ```

   This is only the routing fragment; configure PHP-FPM, access controls and the rest of the server separately.

The web installer is at `/setup.php`. It requires the local setup token from `resources/config/.setup_token`; if that file cannot be written, fix ownership first. The CLI installer is preferable when you already have shell access. After installation, block or remove the setup endpoint from the served deployment and remove any old deployed copy of `public/info.php`; it has been removed from the source.

For a local trial, PHP's built-in server can run the application's router:

```sh
php -S 127.0.0.1:8080 -t public public/index.php
```

It is a development server. Visit `http://127.0.0.1:8080/en/` after completing the database installation.

## Docker deployment

The root Compose file combines the PHP application with the Rust suite. Copy
`.env.example` to `.env` and set the database credentials. These values configure
Compose; they do not automatically generate the application's PHP configuration.
For an existing database, first set `MARIADB_IMAGE` to the version already in use.
The `mariadb:11.4` default is for fresh installations, not a downgrade instruction.

```sh
docker compose up -d --build --no-deps mariadb phpfpm nginx
docker compose run --rm --no-deps phpfpm php tools/install.php
docker compose run --rm --no-deps phpfpm php tools/warm-template-cache.php --clear
```

In the interactive installer, use host `mariadb` and the database name, user and
password from `.env`. The CLI entrypoint runs as `www-data`. For commands in an
already running container, use `docker compose exec --user www-data phpfpm ...`.
The web wizard also works after the database is ready; retrieve its setup token
with `docker compose exec --user www-data phpfpm cat resources/config/.setup_token`.

The default build explicitly selects `production`, without Xdebug. Development
uses `docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build`.
Source is mounted read-only. Named volumes hold config, template cache, catalogs,
avatars and board/chat/media uploads. On first start, existing checkout state is
copied into those volumes without replacing files already there.

**After initialization, the volumes are the live configuration and data.** Editing
the host checkout's config, catalogs or uploads does not edit the running state.
New top-level config files are copied only when absent; merge changes to existing
config and catalogs deliberately during an upgrade. Back up these volumes and the
database together. `docker compose down -v` deletes them.

nginx binds host loopback. PHP reaches Rust services by their Compose service
names. Start the rest with `docker compose up -d --build` and configure the bridges
as described in [the integration guide](suite-search-modules.md).

The production build/install workflow exercises this path on a clean CI runner.
A local PHP test run does not replace that deployment check.

## Inspect modules

```sh
php tools/module.php status
```

Start with the features you need. Read [modules](MODULES.md) before disabling or purging them. A disabled module's files and data remain in the checkout/deployment.

## Development checks

Run the integrity checks from the repository root:

```sh
php tools/check_lang_parity.php
php tools/check_diagnostics.php
php tools/check_modules.php
php tools/check_config.php
```

Each PHP test file is independently executable. The CI workflow runs `tests/*_test.php` and `tests/suite-bridge/*_test.php` and includes a separate MariaDB install/module matrix. `schema_convergence_test.php` needs a database; a skip is not a successful schema comparison.

Use the PHPStan version and configuration pinned in `.github/workflows/ci.yml`. For Rust:

```sh
cd astrx-suite
cargo check --workspace --all-features --all-targets --locked
cargo test --workspace --no-default-features --locked
cargo test --workspace --all-features --locked
```

Run crash/process tests on a normal host with working process facilities. For deployment changes, also test the actual image and installation instructions; passing language-level checks does not verify them.
