#!/bin/sh
set -eu
if [ "$(id -u)" = 0 ]; then
    for part in config template/cache avatar lang board_uploads chat_uploads media_uploads; do
        dest="/app/resources/$part"
        seed="/app/resource-seed/$part"
        mkdir -p "$dest"
        if [ ! -e "$dest/.astrx-initialized" ]; then
            # Include existing private config/state when migrating a checkout.
            # -n preserves any state already copied into this volume.
            if [ -d "$seed" ]; then cp -an "$seed/." "$dest/"; fi
            chown -R www-data:www-data "$dest"
            touch "$dest/.astrx-initialized"
        fi
    done
    # Supply newly introduced defaults without overwriting installed settings.
    for src in /app/resource-seed/config/*.php; do
        [ -f "$src" ] || continue
        dest="/app/resources/config/$(basename "$src")"
        if [ ! -e "$dest" ]; then cp "$src" "$dest"; chown www-data:www-data "$dest"; fi
    done
    chmod 0750 /app/resources/config
    find /app/resources/config -type f -exec chmod 0600 {} +
    if [ "${1:-}" != php-fpm ]; then exec su-exec www-data docker-php-entrypoint "$@"; fi
fi
exec docker-php-entrypoint "$@"
