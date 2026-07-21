#!/usr/bin/env bash
set -Eeuo pipefail

PHP_SERVICE="${PHP_SERVICE:-phpfpm}"
BASE_URL="${BASE_URL:-http://localhost}"
OUT_DIR="${OUT_DIR:-./xdebug-profiles}"
XDEBUG_DIR="/tmp/xdebug"

URLS=(
  "/en/main"
  "/en/admin-navbar"
  "/en/admin-config-access"
  "/en/admin-comments"
  "/en/js/main"
  "/compiled.php"
  "/en/js/admin-navbar"
  "/en/admin-navbar"
)

say() {
  printf '\n\033[1;36m%s\033[0m\n' "$*"
}

die() {
  printf '\n\033[1;31mERROR:\033[0m %s\n' "$*" >&2
  exit 1
}

compose() {
  docker compose "$@"
}

say "Checking Docker Compose service: ${PHP_SERVICE}"
compose ps "$PHP_SERVICE" >/dev/null || die "Service '${PHP_SERVICE}' not found. Run with PHP_SERVICE=your_service_name ./xdebug-profile-once.sh"

say "Writing Xdebug profiler config inside ${PHP_SERVICE}"
compose exec -T "$PHP_SERVICE" sh -lc "
set -eu
mkdir -p '${XDEBUG_DIR}'
chmod 777 '${XDEBUG_DIR}'

cat > /usr/local/etc/php/conf.d/99-xdebug-profile.ini <<'INI'
xdebug.mode=develop,debug,profile
xdebug.start_with_request=trigger
xdebug.output_dir=/tmp/xdebug
xdebug.profiler_output_name=cachegrind.out.%t.%p
xdebug.log=/tmp/xdebug/xdebug.log
xdebug.log_level=7
INI

echo '--- written config ---'
cat /usr/local/etc/php/conf.d/99-xdebug-profile.ini
"

say "Restarting ${PHP_SERVICE}"
compose restart "$PHP_SERVICE" >/dev/null

say "Verifying Xdebug config after restart"
compose exec -T "$PHP_SERVICE" sh -lc "
set -eu
mkdir -p '${XDEBUG_DIR}'
chmod 777 '${XDEBUG_DIR}'

php -i | grep -Ei 'xdebug.mode|xdebug.start_with_request|xdebug.output_dir|xdebug.profiler_output_name|xdebug.log' || true
"

say "Cleaning old profile files"
compose exec -T "$PHP_SERVICE" sh -lc "rm -f '${XDEBUG_DIR}'/cachegrind.out.* '${XDEBUG_DIR}'/xdebug.log || true"

say "Triggering profiler requests"
for path in "${URLS[@]}"; do
  url="${BASE_URL}${path}"
  sep="?"
  [[ "$url" == *"?"* ]] && sep="&"
  trigger_url="${url}${sep}XDEBUG_TRIGGER=1"

  printf 'GET %s\n' "$trigger_url"
  curl -fsS "$trigger_url" >/dev/null || printf '  request failed, continuing\n' >&2
done

say "Waiting briefly for PHP-FPM to flush profile files"
sleep 1

say "Listing files inside container"
compose exec -T "$PHP_SERVICE" sh -lc "
set -eu
ls -lah '${XDEBUG_DIR}' || true
echo
echo '--- xdebug log ---'
tail -200 '${XDEBUG_DIR}/xdebug.log' 2>/dev/null || true
"

say "Copying Cachegrind files out using tar stream"
mkdir -p "$OUT_DIR"

# Copy via stdout tar stream, avoiding docker compose cp weirdness.
if compose exec -T "$PHP_SERVICE" sh -lc "cd '${XDEBUG_DIR}' && ls cachegrind.out.* >/dev/null 2>&1 && tar czf - cachegrind.out.* xdebug.log 2>/dev/null" | tar xzf - -C "$OUT_DIR"; then
  :
else
  die "No cachegrind files were produced. Check the Xdebug log above. Most likely FPM is not loading the new ini, or Xdebug profiling mode is unavailable."
fi

say "Copied profiles to ${OUT_DIR}"
ls -lah "$OUT_DIR"

say "Done"
cat <<EOF

Open one with:

  kcachegrind ${OUT_DIR}/cachegrind.out.*

or:

  qcachegrind ${OUT_DIR}/cachegrind.out.*

Useful env overrides:

  PHP_SERVICE=php ./xdebug-profile-once.sh
  BASE_URL=http://localhost:8080 ./xdebug-profile-once.sh
  OUT_DIR=./profiles ./xdebug-profile-once.sh

EOF
