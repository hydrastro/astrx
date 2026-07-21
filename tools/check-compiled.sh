#!/usr/bin/env bash
set -Eeuo pipefail
PHP_SERVICE="${PHP_SERVICE:-phpfpm}"

echo "== Host compile =="
php tools/compile.php
php tools/verify-compiled.php

echo
if command -v docker >/dev/null 2>&1 && docker compose ps "$PHP_SERVICE" >/dev/null 2>&1; then
  echo "== Docker visibility check (${PHP_SERVICE}) =="
  docker compose exec -T "$PHP_SERVICE" sh -lc 'pwd; ls -lah /app/build; php tools/verify-compiled.php'
else
  echo "Docker service '${PHP_SERVICE}' is not running or docker compose is unavailable; skipped container check."
fi

echo
echo "Compiled front controller: http://localhost/compiled.php"
echo "Normal front controller remains: http://localhost/"
