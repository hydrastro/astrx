#!/bin/sh
# Destructive cleanup is confined to the disposable CI runner's Compose project.
set -eu
[ "${CI:-}" = true ] || { echo 'Run this disposable deployment test in CI only.' >&2; exit 2; }
export DB_NAME=content_manager DB_USER=astrx_ci DB_PASSWORD=ci-database-password-123
export MARIADB_ROOT_PASSWORD_HASH='*1484B3DBA684DBB346F7C491F74D64E3ACB9DDE3'
trap 'docker compose down --volumes --remove-orphans' EXIT
# Select only the PHP deployment; no Rust image build is needed for this gate.
docker compose up -d --build --no-deps mariadb phpfpm nginx
ready=0
for attempt in $(seq 1 60); do
    if docker compose exec -T mariadb healthcheck.sh --connect --innodb_initialized; then ready=1; break; fi
    sleep 2
done
[ "$ready" = 1 ]
# FPM must be able to persist the first-run token under default checkout modes.
curl --retry 10 --retry-connrefused --retry-delay 1 -fsS http://127.0.0.1/setup.php >/dev/null
docker compose exec -T phpfpm sh -c 'test "$(stat -c %a resources/config/.setup_token)" = 600'
docker compose run --rm --no-deps phpfpm php tools/install.php --no-input \
    --db-host=mariadb --db-name="$DB_NAME" --db-user="$DB_USER" --db-pass="$DB_PASSWORD" \
    --admin-user=admin --admin-pass=ci-admin-password-123 --admin-mailbox=admin \
    --environment=production
docker compose run --rm --no-deps phpfpm php tools/warm-template-cache.php --clear --quiet
docker compose exec -T --user www-data phpfpm php -r '
foreach (["pdo_mysql", "gd", "exif", "gettext", "mbstring", "openssl"] as $ext) {
    if (!extension_loaded($ext)) { throw new RuntimeException("Missing extension: " . $ext); }
}
if (extension_loaded("xdebug")) { throw new RuntimeException("Xdebug in production"); }
if (is_writable("src/AstrX/Prelude.php")) { throw new RuntimeException("Writable source"); }
foreach (["config", "template/cache", "avatar", "lang", "board_uploads", "chat_uploads", "media_uploads"] as $part) {
    $file = "resources/" . $part . "/.ci-write";
    if (file_put_contents($file, "ok") !== 2) { throw new RuntimeException("Unwritable: " . $part); }
    unlink($file);
}'
curl -fsS http://127.0.0.1/en/ >/dev/null
[ "$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1/setup.php)" = 404 ]
