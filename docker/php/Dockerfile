FROM php:8.4-fpm-alpine AS base

WORKDIR /app

COPY /docker/php/php.ini /usr/local/etc/php/php.ini

# NOTE: this is the Alpine image, so packages come from apk (not apt-get).
# gettext + gettext-dev provide libintl for the PHP gettext extension, which
# DanWin le-chat uses for its .po/.mo translations.
RUN apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        freetype-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        gettext-dev \
    && apk add --no-cache \
        freetype \
        libjpeg-turbo \
        libpng \
        gettext su-exec \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd pdo pdo_mysql exif gettext \
    && apk del --no-network .build-deps

COPY /docker/php/entrypoint.sh /usr/local/bin/astrx-entrypoint
RUN chmod 0755 /usr/local/bin/astrx-entrypoint
ENTRYPOINT ["astrx-entrypoint"]
CMD ["php-fpm"]


FROM base AS dev
RUN apk add --no-cache --virtual .debug-build-deps $PHPIZE_DEPS linux-headers \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && apk del --no-network .debug-build-deps

# The default build is production; development must be selected explicitly.
FROM base AS production
