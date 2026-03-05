FROM php:8.4-fpm-alpine

WORKDIR /app

COPY /docker/php/php.ini /usr/local/etc/php/php.ini
#COPY /docker/php/php-fpm.conf /usr/local/etc/php-fpm.d/php-fpm.conf

RUN docker-php-ext-install pdo pdo_mysql

CMD [ "php-fpm"]
