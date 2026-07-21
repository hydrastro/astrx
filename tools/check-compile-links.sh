#!/usr/bin/env bash
set -Eeuo pipefail

BASE_URL="${BASE_URL:-http://localhost}"
ROUTE="${ROUTE:-/compile/en/main}"
TMP="$(mktemp)"
HDR="$(mktemp)"
trap 'rm -f "$TMP" "$HDR"' EXIT

url="${BASE_URL}${ROUTE}"
printf 'Checking %s\n' "$url"
curl -fsS -D "$HDR" "$url" -o "$TMP"

if ! grep -qi '^X-AstrX-Compiled: prefix=/compile' "$HDR"; then
  echo 'ERROR: response did not come from the compiled prefix front controller.' >&2
  echo 'Headers:' >&2
  cat "$HDR" >&2
  exit 1
fi

if grep -Eqi '\b(href|src|action|formaction|poster)=("|'"'"')/en/' "$TMP"; then
  echo 'ERROR: found internal /en/... links/assets/forms that escaped /compile.' >&2
  grep -Eoi '\b(href|src|action|formaction|poster)=("|'"'"')/en/[^"'"'"' <]+' "$TMP" | sort -u | head -50 >&2
  exit 1
fi

if grep -Eqi '\b(href|src|action|formaction|poster)=("|'"'"')/compile/en/' "$TMP"; then
  echo 'OK: compiled prefix header is present and internal links use /compile/en/...'
else
  echo 'OK: compiled prefix header is present. No /en/... leaks found; this page may have no internal links.'
fi
