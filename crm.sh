#!/usr/bin/env bash
#
# Atalhos para o dia a dia do CRM-V2 rodando em Docker — versão Linux do crm.ps1.
#
# Nada do stack (PHP, MySQL, Redis) está instalado na máquina — tudo vive dentro dos
# containers. Sem isto, todo comando vira `docker compose exec -T app php artisan ...`.
#
# Uso:
#   ./crm.sh up                      # sobe tudo
#   ./crm.sh down                    # derruba tudo
#   ./crm.sh art migrate             # qualquer comando artisan
#   ./crm.sh test                    # suíte completa (banco palma_v2_test)
#   ./crm.sh test tests/Feature/X.php
#   ./crm.sh perf --rotas=dashboard  # baseline de performance
#   ./crm.sh redis INFO keyspace     # redis-cli
#   ./crm.sh mysql                   # cliente mysql no banco de dev
#   ./crm.sh logs app                # logs de um serviço
#   ./crm.sh sh app                  # shell dentro de um container

set -euo pipefail
cd "$(dirname "$0")"

comando="${1:-help}"
shift || true

case "$comando" in
  up)    docker compose up -d "$@" ;;
  down)  docker compose down "$@" ;;
  ps)    docker compose ps ;;
  logs)  docker compose logs -f "$@" ;;

  art|artisan) docker compose exec -T app php artisan "$@" ;;
  tinker)      docker compose exec app php artisan tinker "$@" ;;

  # O phpunit.xml aponta pra palma_v2_test com force="true", e tests/TestCase.php
  # aborta se o banco não tiver "test" no nome (Regra de ouro nº 7).
  test) docker compose exec -T app php artisan test "$@" ;;

  perf) docker compose exec -T app php artisan perf:baseline "$@" ;;

  redis)
    if [ $# -eq 0 ]; then docker compose exec redis redis-cli
    else docker compose exec -T redis redis-cli "$@"; fi ;;

  mysql)
    if [ $# -eq 0 ]; then docker compose exec mysql mysql -uroot -proot palma_v2
    else docker compose exec -T mysql mysql -uroot -proot palma_v2 "$@"; fi ;;

  sh) docker compose exec "${1:-app}" sh ;;

  *) sed -n '2,/^$/p' "$0" | sed 's/^# \{0,1\}//' ;;
esac
