FROM php:8.3-cli-alpine

RUN apk update && apk add --no-cache \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        libzip-dev \
        icu-dev \
        oniguruma-dev \
        linux-headers \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql bcmath gd zip exif opcache intl pcntl \
    # phpredis: extensão C que faz o PHP falar o protocolo do Redis. Usada por
    # CACHE_STORE/SESSION_DRIVER/QUEUE_CONNECTION=redis. É a mesma que o Forge instala
    # em produção (php8.3-redis), então dev e prod usam o mesmo client.
    # Precisa compilar (pecl), por isso as build-deps entram e saem na mesma camada.
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/dev/local.ini /usr/local/etc/php/conf.d/local.ini

WORKDIR /var/www
