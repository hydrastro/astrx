#!/usr/bin/env bash
set -Eeuo pipefail

PHP_SERVICE="${PHP_SERVICE:-phpfpm}"
BASE_URL="${BASE_URL:-http://localhost}"
HEAVY_ROUTE="${HEAVY_ROUTE:-admin-config-access}"
LOAD_FACTOR="${LOAD_FACTOR:-2}"
OUT_ROOT="${OUT_ROOT:-./xdebug-profiles}"
XDEBUG_DIR="/tmp/xdebug"
COOKIE_HEADER="${COOKIE_HEADER:-}"

stamp="$(date +%Y%m%d-%H%M%S)"
OUT_DIR="${OUT_ROOT}/modes-${stamp}"

say(){ printf '\n\033[1;36m%s\033[0m\n' "$*"; }
die(){ printf '\n\033[1;31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }
compose(){ docker compose "$@"; }

curl_args=(-fsS)
if [[ -n "$COOKIE_HEADER" ]]; then
  curl_args+=(-H "Cookie: ${COOKIE_HEADER}")
fi

trigger_url(){
  local path="$1"
  local sep='?'
  [[ "$path" == *'?'* ]] && sep='&'
  printf '%s%s%sXDEBUG_TRIGGER=1' "$BASE_URL" "$path" "$sep"
}

profile_one(){
  local name="$1"
  local path="$2"
  shift 2
  local extra_curl_args=("$@")
  local mode_dir="${OUT_DIR}/${name}"
  mkdir -p "$mode_dir"

  say "Profiling ${name}: ${path}"
  compose exec -T "$PHP_SERVICE" sh -lc "rm -f '${XDEBUG_DIR}'/cachegrind.out.* '${XDEBUG_DIR}'/xdebug.log || true"

  local url
  url="$(trigger_url "$path")"

  # Warmup/load-factor requests. Xdebug is still triggered so profile files are
  # produced for each request; this intentionally models repeated navigation.
  for ((i=1; i<=LOAD_FACTOR; i++)); do
    printf 'GET [%s/%s] %s\n' "$i" "$LOAD_FACTOR" "$url"
    curl "${curl_args[@]}" "${extra_curl_args[@]}" "$url" >"${mode_dir}/response-${i}.html" || true
  done

  sleep 1
  if compose exec -T "$PHP_SERVICE" sh -lc "cd '${XDEBUG_DIR}' && ls cachegrind.out.* >/dev/null 2>&1 && tar czf - cachegrind.out.* xdebug.log 2>/dev/null" | tar xzf - -C "$mode_dir"; then
    :
  else
    printf 'No cachegrind files for %s\n' "$name" >"${mode_dir}/NO_CACHEGRIND.txt"
  fi

  {
    echo "mode=${name}"
    echo "path=${path}"
    echo "url=${url}"
    echo "load_factor=${LOAD_FACTOR}"
    echo "cookie_header_present=$([[ -n "$COOKIE_HEADER" ]] && echo yes || echo no)"
  } >"${mode_dir}/meta.txt"
}

say "Checking Docker Compose service: ${PHP_SERVICE}"
compose ps "$PHP_SERVICE" >/dev/null || die "Service '${PHP_SERVICE}' not found. Use PHP_SERVICE=name."

say "Enabling Xdebug profiler in ${PHP_SERVICE}"
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
"

say "Restarting ${PHP_SERVICE}"
compose restart "$PHP_SERVICE" >/dev/null

say "Verifying profiler config"
compose exec -T "$PHP_SERVICE" sh -lc "
mkdir -p '${XDEBUG_DIR}'
chmod 777 '${XDEBUG_DIR}'
php -i | grep -Ei 'xdebug.mode|xdebug.start_with_request|xdebug.output_dir|xdebug.profiler_output_name|xdebug.log' || true
"

mkdir -p "$OUT_DIR"

# Server-side modes. JS is split into shell and fragment because curl does not
# execute browser JavaScript. The fragment request is the expensive PHP page
# fetch that the JS runtime performs after the shell boots.
profile_one normal "/en/${HEAVY_ROUTE}"
profile_one compiled "/compile/en/${HEAVY_ROUTE}"
profile_one js_shell "/en/js/${HEAVY_ROUTE}"
profile_one js_fragment "/en/${HEAVY_ROUTE}" -H 'X-AstrX-JS-Browser: 1'
profile_one compiled_js_shell "/compile/en/js/${HEAVY_ROUTE}"
profile_one compiled_js_fragment "/compile/en/${HEAVY_ROUTE}" -H 'X-AstrX-JS-Browser: 1'

say "Profiles written to ${OUT_DIR}"
find "$OUT_DIR" -maxdepth 2 -type f | sort

cat <<MSG

Open with:
  kcachegrind ${OUT_DIR}/*/cachegrind.out.*

Useful examples:
  COOKIE_HEADER='PHPSESSID=abc...' HEAVY_ROUTE=admin-navbar LOAD_FACTOR=3 ./tools/profile-modes.sh
  BASE_URL=http://localhost:8080 PHP_SERVICE=php ./tools/profile-modes.sh

MSG
