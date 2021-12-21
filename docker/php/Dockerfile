FROM php:latest

RUN apk add nano
RUN apk add composer

WORKDIR /app
#CMD sh -c "composer install"

COPY /docker/php/php.ini /usr/local/etc/php/php.ini
#COPY /docker/php/php-fpm.conf /usr/local/etc/php-fpm.d/php-fpm.conf
