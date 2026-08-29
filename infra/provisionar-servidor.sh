#!/usr/bin/env bash
#
# Provisiona um app server do CRM-V2 (Ubuntu 24.04 ARM na AWS).
#
# Substitui o Laravel Forge: faz o que ele faria — PHP, nginx, Composer, Node — mas
# versionado no repositório, então é reproduzível e auditável. Rodar como root na máquina:
#
#   sudo bash provisionar-servidor.sh
#
# Inclui os clientes `mysql` e `redis-cli` de propósito: não são usados pela aplicação
# (o PHP fala com os dois pelas suas próprias extensões), mas TODO o diagnóstico da
# seção 12 do docs/deploy-aws.md depende deles — e a carga inicial de dados, da seção 6,
# é literalmente um `mysql < dump.sql` rodado a partir daqui.
#
# É IDEMPOTENTE: rodar de novo não quebra nada, só reaplica a configuração.
#
# Este script NÃO faz deploy da aplicação nem configura daemons — isso é o
# `deploy.sh` e o `configurar-daemons.sh`, que dependem do .env já existir.

set -euo pipefail

APP_DIR=/var/www/crm
APP_USER=ubuntu

echo "==> Pacotes do sistema"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get upgrade -y -qq

# Ubuntu 24.04 (noble) já traz PHP 8.3 no repositório oficial — não precisa de PPA.
# As extensões são exatamente as do docker/dev/Dockerfile, para dev e produção
# rodarem o mesmo conjunto.
#
# ⚠️ php8.3-opcache é pacote SEPARADO no Ubuntu e NÃO vem por dependência do
# php8.3-cli/fpm. Sem ele o provisionamento passa, o site sobe e tudo parece certo —
# só que cada requisição recompila o PHP inteiro. Foi pego pela conferência abaixo no
# provisionamento real de 2026-08-28. Não remover da lista.
apt-get install -y -qq \
  nginx \
  php8.3-fpm php8.3-cli php8.3-common \
  php8.3-mysql php8.3-redis php8.3-mbstring php8.3-xml php8.3-curl \
  php8.3-zip php8.3-gd php8.3-bcmath php8.3-intl php8.3-opcache \
  git unzip curl supervisor \
  mysql-client redis-tools

echo "==> Conferindo extensões obrigatórias"
# ⚠️ O OPcache se apresenta no `php -m` como "Zend OPcache", não como "opcache" — ele sai
# na seção [Zend Modules]. Comparar direto com "^opcache$" dá falso negativo mesmo com a
# extensão instalada e ativa (custou uma rodada no provisionamento real de 2026-08-28).
# Daí normalizar: minúsculas + remover o prefixo "zend ".
MODULOS=$(php -m | tr '[:upper:]' '[:lower:]' | sed 's/^zend //')
FALTANDO=""
for ext in pdo_mysql redis mbstring xml curl zip gd bcmath intl opcache pcntl exif; do
  echo "$MODULOS" | grep -qx "$ext" || FALTANDO="$FALTANDO $ext"
done
if [ -n "$FALTANDO" ]; then
  echo "ERRO: extensões faltando:$FALTANDO"
  exit 1
fi
echo "    todas presentes"

echo "==> Composer"
if ! command -v composer > /dev/null; then
  curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php
  php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet
  rm -f /tmp/composer-setup.php
fi
composer --version

echo "==> Node.js 22 (para o build do Vite)"
if ! command -v node > /dev/null || [ "$(node -v | cut -d. -f1)" != "v22" ]; then
  curl -fsSL https://deb.nodesource.com/setup_22.x | bash - > /dev/null 2>&1
  apt-get install -y -qq nodejs
fi
node -v

echo "==> Configuração do PHP"
# ⚠️ php_value, NÃO php_admin_value: o trait ExportaPlanilha chama ini_set('memory_limit')
# em runtime para os exports síncronos, e php_admin_value tornaria isso impossível de
# sobrescrever — o export daria 500 silencioso só em produção.
cat > /etc/php/8.3/fpm/conf.d/99-crm.ini <<'INI'
memory_limit = 512M
max_execution_time = 60
upload_max_filesize = 16M
post_max_size = 16M

; validate_timestamps=0 elimina um stat() por arquivo incluído em CADA requisição.
; Custo: o código novo só passa a valer depois de recarregar o FPM (o deploy.sh faz).
opcache.enable = 1
opcache.validate_timestamps = 0
opcache.memory_consumption = 256
opcache.max_accelerated_files = 20000
opcache.interned_strings_buffer = 16
realpath_cache_size = 4096k
INI

cp /etc/php/8.3/fpm/conf.d/99-crm.ini /etc/php/8.3/cli/conf.d/99-crm.ini
# O CLI roda migrations, imports e o worker: precisa de mais folga que o FPM.
sed -i 's/^memory_limit = 512M/memory_limit = 1024M/; s/^max_execution_time = 60/max_execution_time = 0/' \
  /etc/php/8.3/cli/conf.d/99-crm.ini

echo "==> Pool do PHP-FPM"
# pm=static evita o custo de fork a cada requisição. 12 workers × ~500MB de pico no pior
# caso (export síncrono) contra 7GB de RAM — com folga para o SO e o worker de fila.
cat > /etc/php/8.3/fpm/pool.d/crm.conf <<CONF
[crm]
user = ${APP_USER}
group = ${APP_USER}
listen = /run/php/php8.3-fpm-crm.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

pm = static
pm.max_children = 12
pm.max_requests = 500

catch_workers_output = yes
php_admin_value[error_log] = /var/log/php8.3-fpm-crm.log
php_admin_flag[log_errors] = on
CONF

# O pool default (www) não é usado — deixá-lo de pé só consome memória.
rm -f /etc/php/8.3/fpm/pool.d/www.conf

echo "==> Diretório da aplicação"
mkdir -p "$APP_DIR"
chown -R ${APP_USER}:${APP_USER} "$APP_DIR"

echo "==> nginx"
rm -f /etc/nginx/sites-enabled/default
cat > /etc/nginx/sites-available/crm <<'NGINX'
server {
    listen 80 default_server;
    server_name _;
    root /var/www/crm/public;

    index index.php;
    charset utf-8;

    # ⚠️ O TLS termina no ALB, que fala HTTP com esta máquina. O Laravel descobre que a
    # requisição original era HTTPS pelos headers X-Forwarded-*, confiados via
    # trustProxies no bootstrap/app.php.

    # O JSON do Inertia comprime muito: cada navegação é um payload de 15-90 KB.
    gzip on;
    gzip_comp_level 5;
    gzip_min_length 256;
    gzip_types application/json application/javascript text/css text/plain application/xml image/svg+xml;

    client_max_body_size 16M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # Assets com hash no nome nunca mudam de conteúdo — cache eterno.
    location /build/ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        try_files $uri =404;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm-crm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        include fastcgi_params;

        # Precisa acomodar os exports síncronos, que podem passar de 60s.
        fastcgi_read_timeout 300;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX

ln -sf /etc/nginx/sites-available/crm /etc/nginx/sites-enabled/crm
nginx -t

echo "==> Reiniciando serviços"
systemctl restart php8.3-fpm
systemctl restart nginx
systemctl enable php8.3-fpm nginx supervisor > /dev/null

echo ""
echo "=== PROVISIONAMENTO CONCLUÍDO ==="
php -v | head -1
nginx -v 2>&1
echo "app dir: $APP_DIR (vazio — rode o deploy.sh)"
