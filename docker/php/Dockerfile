FROM php:8.3-fpm-alpine

RUN apk update && apk add --no-cache \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        libzip-dev \
        icu-dev \
        oniguruma-dev \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql bcmath gd zip exif opcache intl \
    # phpredis — precisa estar aqui TAMBÉM, senão o teste de carga sobe sem o client e
    # morre no primeiro request (CACHE_STORE/SESSION_DRIVER/QUEUE_CONNECTION=redis).
    # ⚠️ Duplicado em docker/dev/Dockerfile de propósito por ora: as duas imagens têm
    # bases diferentes (fpm x cli) e propósitos diferentes. Se divergirem mais, extrair
    # uma imagem base comum — hoje o custo da indireção seria maior que o da cópia.
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

COPY docker/php/www.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/php/local.ini /usr/local/etc/php/conf.d/local.ini

WORKDIR /var/www

# Copia o codigo pra dentro da imagem em vez de bind-mount do Windows — e o que
# elimina o custo de I/O de milhares de arquivos pequenos atravessando a ponte
# Windows->container, e tambem e o jeito fiel de simular producao (Forge tambem
# tem o codigo no disco nativo do Linux, nao montado de outro SO).
#
# ⚠️ O vendor/ NAO vem do host (esta no .dockerignore): desde a migracao pro Docker
# em 2026-08-04 ele vive no volume nomeado `vendor_data` e o diretorio no host e so
# o ponto de montagem, VAZIO. Ate 2026-08-27 este Dockerfile copiava esse vazio e a
# imagem subia sem autoload — o loadtest estava quebrado e ninguem tinha percebido,
# porque nao foi rodado nesse intervalo.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY . /var/www

# --no-dev + --optimize-autoloader: mesma configuracao do deploy de producao, que e
# justamente o que este ambiente existe pra simular.
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress \
    # Recria o symlink public/storage, que vem excluído do contexto (ver .dockerignore).
    && php artisan storage:link \
    && chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache \
    # ⚠️ O chmod é tão necessário quanto o chown. Os arquivos vêm de um filesystem
    # Windows, que não carrega o bit de escrita do Unix: os diretórios chegam como
    # `dr-xr-xr-x` e o PHP-FPM (www-data) não consegue gravar as views compiladas nem
    # os logs — a aplicação responde 500 em TODA requisição, com "Failed to open
    # stream: Permission denied". Só o chown não resolve, porque o dono certo sobre um
    # diretório sem `w` continua sem poder escrever.
    && chmod -R u+rwX,g+rwX /var/www/storage /var/www/bootstrap/cache
