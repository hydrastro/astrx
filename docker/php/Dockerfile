FROM php:8.4-fpm-alpine

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
        gettext \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd pdo pdo_mysql gettext \
    && apk del --no-network .build-deps

CMD [ "php-fpm" ]

# =============================================================================
# Development stage — NOT for production use
# =============================================================================
# Build: docker build --target dev -t astrx-dev -f docker/php/Dockerfile .
#
# In docker-compose.yml (dev service):
#   build:
#     context: .
#     dockerfile: docker/php/Dockerfile
#     target: dev
#   extra_hosts:
#     - "host.docker.internal:host-gateway"   # Linux only — Mac/Win: already works
#
# Your IDE (PhpStorm / VSCode + PHP Debug) should listen on port 9003.
# =============================================================================
FROM php:8.4-fpm-alpine AS dev

WORKDIR /app

COPY /docker/php/php.ini /usr/local/etc/php/php.ini

RUN apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        freetype-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        gettext-dev \
        linux-headers \
    && apk add --no-cache \
        freetype \
        libjpeg-turbo \
        libpng \
        gettext \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd pdo pdo_mysql exif gettext \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && apk del --no-network .build-deps

CMD [ "php-fpm" ]
