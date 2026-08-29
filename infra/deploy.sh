#!/usr/bin/env bash
#
# Faz deploy do CRM-V2 num app server já provisionado (ver provisionar-servidor.sh).
#
# Rodar como o usuário da aplicação (ubuntu), NÃO como root:
#
#   bash deploy.sh                # deploy normal
#   bash deploy.sh --migrar       # deploy + migrations (ver aviso abaixo)
#
# ⚠️ --migrar em EXATAMENTE UM nó por deploy. São dois app servers apontando para o mesmo
# RDS; rodar migrations nos dois ao mesmo tempo é corrida por um recurso único. O Laravel
# segura um lock durante a migration, então o segundo provavelmente só esperaria — mas
# "provavelmente" não é garantia que se queira num schema de produção.
#
# É IDEMPOTENTE: a primeira execução clona, as seguintes atualizam.

set -euo pipefail

APP_DIR=/var/www/crm
REPO=https://github.com/ne0ngh0st/CRM_V2.git
BRANCH=main

MIGRAR=0
[ "${1:-}" = "--migrar" ] && MIGRAR=1

if [ "$(id -u)" = "0" ]; then
  echo "ERRO: não rode como root — os arquivos ficariam com dono errado e o PHP-FPM"
  echo "      (que roda como ubuntu) não conseguiria escrever em storage/."
  exit 1
fi

echo "==> Código"
# ⚠️ init + fetch em vez de clone: o .env já está no diretório antes do primeiro deploy,
# e `git clone` recusa diretório não-vazio. Assim a primeira carga e as seguintes seguem
# exatamente o mesmo caminho — um caso a menos para dar errado só na primeira vez.
mkdir -p "$APP_DIR"
cd "$APP_DIR"
[ -d .git ] || git init -q
git remote add origin "$REPO" 2>/dev/null || git remote set-url origin "$REPO"
git fetch -q origin "$BRANCH"
# reset --hard em vez de pull: o diretório de deploy não é área de trabalho, e um
# conflito de merge aqui deixaria a aplicação num estado meio-antigo meio-novo.
git reset --hard "origin/$BRANCH"
# ⚠️ `clean -fd` sem -x, de propósito: -x apagaria arquivos ignorados pelo git — ou seja,
# o .env e o node_modules. Sem -x, ele só remove lixo não rastreado e não ignorado.
git clean -fd
echo "    commit: $(git rev-parse --short HEAD) — $(git log -1 --pretty=%s)"

# ⚠️ O .env é ignorado pelo git, então sobrevive ao reset acima. Mas se ele não existir,
# TODO comando artisan abaixo falha de um jeito confuso (fala de APP_KEY, não de .env).
if [ ! -f "$APP_DIR/.env" ]; then
  echo ""
  echo "ERRO: $APP_DIR/.env não existe."
  echo "      Crie-o antes do primeiro deploy (ver docs/deploy-aws.md seção 5.1)."
  exit 1
fi

echo "==> Dependências PHP"
# --no-dev: nada de PHPUnit/Faker em produção.
# --classmap-authoritative: elimina o stat() por classe no autoload; casa com o
# opcache.validate_timestamps=0 que o provisionamento aplicou.
composer install --no-interaction --prefer-dist --optimize-autoloader \
  --no-dev --classmap-authoritative

echo "==> Assets"
# npm ci (não install): instala exatamente o package-lock.json, sem resolver versões.
# As devDependencies SÃO necessárias aqui — o Vite é uma delas.
npm ci --no-audit --no-fund
npm run build

if [ "$MIGRAR" = "1" ]; then
  echo "==> Migrations"
  php artisan migrate --force
else
  echo "==> Migrations: PULADAS (rode com --migrar em um dos nós)"
fi

echo "==> Caches de produção"
# ⚠️ A ORDEM importa: config:cache primeiro, porque os outros leem config.
# ⚠️ E o clear antes: cache velho de uma versão anterior do config pode conter chave que
# não existe mais, e o route:cache falharia com erro que não aponta para a causa.
php artisan config:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

echo "==> storage:link"
# Ainda necessário mesmo com UPLOADS_DISK=s3: os registros de faca_recursos anteriores a
# 27/08 guardam caminho no formato "storage/facas/...", servido por este symlink.
php artisan storage:link 2>/dev/null || echo "    (link já existia)"

echo "==> Recarregando PHP-FPM"
# Com validate_timestamps=0 o opcache NÃO percebe arquivo novo sozinho — sem este reload
# a máquina continua servindo o código anterior indefinidamente.
sudo systemctl reload php8.3-fpm

echo "==> Reiniciando workers da fila"
# Sinaliza para o worker terminar o job atual e sair; o supervisor sobe um novo, já com o
# código novo. Não mata job no meio.
php artisan queue:restart

# ⚠️ O Reverb também é processo de vida longa: ele carrega o código no boot e mantém em
# memória, então um deploy sem este restart deixa o WebSocket rodando a versão ANTERIOR
# por tempo indeterminado. O `queue:restart` acima não o alcança — ele fala só com os
# workers da fila. Só existe no app-1; nos outros nós o `if` simplesmente não entra.
if sudo supervisorctl status crm-reverb > /dev/null 2>&1; then
  echo "==> Reiniciando o Reverb"
  # Derruba as conexões WebSocket abertas; o cliente (Laravel Echo) reconecta sozinho, e o
  # NotificationBell recarrega o histórico no reconnect. Perda real: nenhuma.
  sudo supervisorctl restart crm-reverb
fi

echo "==> Aquecendo o cache"
# ⚠️ Por último e de propósito: sem isto o primeiro usuário depois do deploy paga a
# agregação fria (~5s). Regra de ouro nº 9.
php artisan cache:aquecer

echo ""
echo "=== DEPLOY CONCLUÍDO ==="
php artisan --version
echo "commit: $(git rev-parse --short HEAD)"
