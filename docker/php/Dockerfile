FROM php:8.4-fpm-alpine

WORKDIR /app

COPY /docker/php/php.ini /usr/local/etc/php/php.ini

RUN apt-get update && apt-get install -y \
        libfreetype6-dev libjpeg62-turbo-dev libpng-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd \
    && rm -rf /var/lib/apt/lists/*

CMD [ "php-fpm"]

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
        linux-headers \
    && apk add --no-cache \
        freetype \
        libjpeg-turbo \
        libpng \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd pdo pdo_mysql exif \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && apk del --no-network .build-deps

CMD [ "php-fpm" ]
