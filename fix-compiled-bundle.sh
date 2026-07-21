#!/usr/bin/env bash
set -Eeuo pipefail

PHP_SERVICE="${PHP_SERVICE:-phpfpm}"

echo "==> Building template cache"
php tools/warm-template-cache.php --clear

echo "==> Building compiled bundle"
php tools/compile.php

echo "==> Host bundle:"
ls -lah build/astrx.compiled.php

echo "==> Finding PHP-FPM container"
CID="$(docker compose ps -q "$PHP_SERVICE")"

if [ -z "$CID" ]; then
  echo "ERROR: Could not find compose service: $PHP_SERVICE"
  echo "Try: PHP_SERVICE=php ./fix-compiled-bundle.sh"
  exit 1
fi

echo "==> Ensuring /app/build exists in container"
docker compose exec -T "$PHP_SERVICE" mkdir -p /app/build

echo "==> Copying compiled bundle into container"
docker cp build/astrx.compiled.php "$CID":/app/build/astrx.compiled.php

echo "==> Verifying container bundle"
docker compose exec -T "$PHP_SERVICE" ls -lah /app/build/astrx.compiled.php

echo "==> Done. Test:"
echo "    http://localhost/compiled.php"
