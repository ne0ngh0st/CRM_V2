#!/usr/bin/env bash
#
# Configura os processos de segundo plano do CRM-V2 via supervisor + cron.
#
# Rodar como root, informando o PAPEL da máquina:
#
#   sudo bash configurar-daemons.sh app-1     # Reverb (WebSocket)
#   sudo bash configurar-daemons.sh app-2     # fila + scheduler
#
# Os dois nós são idênticos em código; o que muda é qual processo roda em cada um.
# Ver docs/deploy-aws.md §1 ("Distribuição de papéis entre os dois nós").
#
# É IDEMPOTENTE.

set -euo pipefail

APP_DIR=/var/www/crm
APP_USER=ubuntu
PAPEL="${1:-}"

if [ "$PAPEL" != "app-1" ] && [ "$PAPEL" != "app-2" ]; then
  echo "ERRO: informe o papel — 'app-1' (Reverb) ou 'app-2' (fila + scheduler)."
  exit 1
fi

[ -f "$APP_DIR/.env" ] || { echo "ERRO: $APP_DIR/.env não existe. Faça o deploy antes."; exit 1; }

# Começa limpando as configs deste projeto, para que trocar o papel de uma máquina não
# deixe o daemon antigo rodando junto com o novo.
rm -f /etc/supervisor/conf.d/crm-*.conf

if [ "$PAPEL" = "app-1" ]; then
  echo "==> Reverb (WebSocket) — só neste nó"
  # ⚠️ Um Reverb só, de propósito: o ALB roteia /app/* e /apps/* para o target group
  # dele. Dois nós publicando WebSocket exigiriam sticky sessions ou um backend de
  # escalonamento — complexidade sem retorno no volume atual.
  # --host=0.0.0.0 para o health check do ALB alcançar a porta pela rede.
  cat > /etc/supervisor/conf.d/crm-reverb.conf <<CONF
[program:crm-reverb]
process_name=%(program_name)s
command=php ${APP_DIR}/artisan reverb:start --host=0.0.0.0 --port=8080
directory=${APP_DIR}
autostart=true
autorestart=true
user=${APP_USER}
redirect_stderr=true
stdout_logfile=/var/log/crm-reverb.log
stopwaitsecs=10
CONF

  # Garante que o scheduler não fique ligado aqui se esta máquina já foi app-2 antes.
  # ⚠️ `|| true` em cada etapa: `crontab -l` sai com erro quando não há crontab, e `grep -v`
  # sai com erro quando o resultado é vazio. Com set -e + pipefail isso aborta o script.
  RESTO=$(crontab -u ${APP_USER} -l 2>/dev/null | grep -v 'artisan schedule:run' || true)
  printf '%s\n' "$RESTO" | grep -v '^$' | crontab -u ${APP_USER} - 2>/dev/null || \
    crontab -u ${APP_USER} -r 2>/dev/null || true
fi

if [ "$PAPEL" = "app-2" ]; then
  echo "==> Worker da fila"
  # ⚠️ --timeout=700 tem que ser MAIOR que o $timeout do job mais longo
  # (GerarExportacaoCarteiraJob declara 600). Se for menor, o worker mata o processo sem
  # passar pelo failed(), e o registro fica preso em "processando" para sempre.
  #
  # ⚠️ stopwaitsecs=720 > 700 pelo mesmo motivo, na outra ponta: no restart, o supervisor
  # precisa esperar o job terminar em vez de matá-lo no meio.
  cat > /etc/supervisor/conf.d/crm-worker.conf <<CONF
[program:crm-worker]
process_name=%(program_name)s_%(process_num)02d
command=php ${APP_DIR}/artisan queue:work redis --tries=1 --timeout=700 --sleep=1 --max-time=3600
directory=${APP_DIR}
autostart=true
autorestart=true
user=${APP_USER}
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/crm-worker.log
stopwaitsecs=720
CONF

  echo "==> Scheduler (cron)"
  # ⚠️ Sem isto o cache warming não roda e as páginas voltam a esfriar — em silêncio,
  # sem erro nenhum. É o erro mais provável de passar despercebido no primeiro deploy.
  # A pill de fogo no Painel existe para tornar isso visível.
  CRON="* * * * * cd ${APP_DIR} && php artisan schedule:run >> /var/log/crm-scheduler.log 2>&1"
  # ⚠️ Montar a lista numa variável ANTES de canalizar. Escrito como
  # `( crontab -l | grep -v ...; echo "$CRON" ) | crontab -`, o `set -e`/`pipefail` mata o
  # subshell no `crontab -l` de uma máquina sem crontab — e o resultado é um crontab VAZIO
  # instalado em silêncio, ou seja, scheduler desligado sem ninguém perceber. Foi o que
  # aconteceu no provisionamento real de 2026-08-28.
  RESTO=$(crontab -u ${APP_USER} -l 2>/dev/null | grep -v 'artisan schedule:run' || true)
  printf '%s\n%s\n' "$RESTO" "$CRON" | grep -v '^$' | crontab -u ${APP_USER} -
fi

echo "==> Logs"
for f in /var/log/crm-reverb.log /var/log/crm-worker.log /var/log/crm-scheduler.log; do
  touch "$f"; chown ${APP_USER}:${APP_USER} "$f"
done

# Rotação: sem isto o log cresce até encher o disco de 30GB — lento o bastante para
# ninguém reparar, e súbito o bastante para derrubar a máquina quando acontecer.
cat > /etc/logrotate.d/crm <<'ROT'
/var/log/crm-*.log {
    daily
    rotate 14
    compress
    delaycompress
    missingok
    notifempty
    copytruncate
}
ROT

echo "==> Aplicando"
supervisorctl reread
supervisorctl update
sleep 2

echo ""
echo "=== DAEMONS CONFIGURADOS ($PAPEL) ==="
supervisorctl status || true
echo "--- crontab de ${APP_USER} ---"
crontab -u ${APP_USER} -l 2>/dev/null || echo "(vazio)"
