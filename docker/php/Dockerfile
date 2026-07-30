FROM php:8.3-fpm-alpine

RUN apk update && apk add --no-cache \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        libzip-dev \
        icu-dev \
        oniguruma-dev \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql bcmath gd zip exif opcache intl

COPY docker/php/www.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/php/local.ini /usr/local/etc/php/conf.d/local.ini

WORKDIR /var/www

# Copia o codigo (incluindo vendor/ ja instalado no host) pra dentro da imagem,
# em vez de bind-mount do Windows — e o que elimina o custo de I/O de milhares
# de arquivos pequenos atravessando a ponte Windows->container, e tambem e o
# jeito fiel de simular producao (Forge tambem tem o codigo no disco nativo
# do Linux, nao montado de outro SO).
COPY . /var/www
RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache
