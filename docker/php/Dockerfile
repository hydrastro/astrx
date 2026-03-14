FROM php:8.4-fpm-alpine

WORKDIR /app

COPY /docker/php/php.ini /usr/local/etc/php/php.ini

RUN apk add --no-cache \
        freetype-dev \
        libjpeg-turbo-dev \
        libpng-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd pdo pdo_mysql

CMD [ "php-fpm"]
