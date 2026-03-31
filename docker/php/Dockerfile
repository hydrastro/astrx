FROM php:8.4-fpm-alpine

WORKDIR /app

COPY /docker/php/php.ini /usr/local/etc/php/php.ini

RUN apk add --no-cache \
        freetype-dev \
        libjpeg-turbo-dev \
        libpng-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd pdo pdo_mysql exif

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

RUN apk add --no-cache \
        freetype-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        linux-headers \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd pdo pdo_mysql exif \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug

CMD [ "php-fpm" ]
