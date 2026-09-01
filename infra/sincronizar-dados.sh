#!/usr/bin/env bash
#
# Sincroniza os dados do palma_v2 LOCAL para o RDS de produção.
#
# Roda da máquina do Tony, onde vive o espelho `autopel01_homolog`. A produção não
# alcança esse espelho, então o caminho é: `legado:import-*` aqui → este script lá.
# Quando o Adriano liberar acesso do TOTVS pela AWS, isto vira obsoleto e os imports
# passam a rodar direto na produção com `--fonte=producao`.
#
# Uso:
#   bash infra/sincronizar-dados.sh --dry-run    # gera o pacote e mostra o plano
#   bash infra/sincronizar-dados.sh              # gera, envia e aplica
#
# ─────────────────────────────────────────────────────────────────────────────
# ⚠️ POR QUE ISTO NÃO É UM mysqldump SEGUIDO DE mysql
#
# O procedimento da §6.1.1 do docs/deploy-aws.md ("dump local → mysql no RDS") foi
# escrito para um banco VAZIO, em 28/08. A produção hoje tem dado que nasceu lá e não
# existe aqui: orçamentos criados na tela, observações, ligações, agendamentos,
# solicitações de cadastro, senhas e fotos dos beta testers. Repetir aquele
# procedimento apaga tudo isso.
#
# ⚠️ E não basta evitar o TRUNCATE: `REPLACE INTO clientes` também destrói, porque
# REPLACE é DELETE + INSERT e dispara os FKs. Medido no schema real:
#
#     observacoes.cliente_id                 → ON DELETE SET NULL
#     ligacoes.cliente_id                    → ON DELETE SET NULL
#     agendamentos_ligacoes.cliente_id       → ON DELETE SET NULL
#     carteira_motivos_inatividade.cliente_id→ ON DELETE CASCADE
#
# Ou seja: um REPLACE em `clientes` desligaria as observações dos seus clientes e
# APAGARIA os motivos de inatividade, sem erro nenhum. Por isso toda tabela-espelho
# entra por tabela de staging + INSERT ... ON DUPLICATE KEY UPDATE, que nunca apaga
# linha.
# ─────────────────────────────────────────────────────────────────────────────

set -euo pipefail

APP_NODE=${APP_NODE:-15.229.96.223}
SSH_KEY=${SSH_KEY:-$HOME/.ssh/crm-v2}
CONTAINER=${CONTAINER:-crm_v2-mysql-1}
DB_LOCAL=${DB_LOCAL:-palma_v2}
DB_USER=${DB_USER:-palma}
DB_PASS=${DB_PASS:-palma}
PACOTE=$(mktemp -t sync-XXXXXX.sql)

DRY_RUN=0
[ "${1:-}" = "--dry-run" ] && DRY_RUN=1

# Espelho puro do TOTVS: nada nasce no v2, mas OUTRAS tabelas apontam para elas.
# Entram por upsert — linha some daqui não some de lá, de propósito: apagar cliente
# derrubaria observação e motivo de inatividade escritos em produção.
ESPELHO_UPSERT="clientes produtos segmentos grupos_cliente"

# ⚠️ `leads` FICOU DE FORA, e não é esquecimento.
#
# O `legado:import-leads` APAGA as linhas `origem='sistema'` e reinsere — os ids mudam
# a cada import local. Isso quebra a sincronização de dois jeitos, os dois silenciosos:
#
#   1. `observacoes.lead_id` e `ligacoes.lead_id` na produção apontam para ids que só
#      fazem sentido no retrato anterior. Um upsert por id religaria a observação a
#      OUTRO lead, sem erro nenhum.
#   2. Lead do WordPress e lead manual nascem na produção e ocupam a mesma faixa de
#      auto_increment que o import local usa. O upsert sobrescreveria um lead do site
#      com um lead do TOTVS.
#
# Para entrar aqui, `leads` precisa antes de uma chave estável de origem (mesmo
# tratamento que `orcamentos` e `observacoes` ganharam com `legado_id`), e o
# `ImportLeadsLegado` precisa deixar de apagar/reinserir. Até lá, lead novo do TOTVS
# não chega à produção por este script.

# pedidos/pedido_itens são os únicos que podem ser substituídos por inteiro: nada
# além de pedido_itens referencia pedidos, e o import local os reconstrói do zero a
# cada rodada (os ids não são estáveis, então upsert por id não faria sentido).
ESPELHO_SUBSTITUI="pedidos pedido_itens"

# Estas duas carregam `legado_id` e entram só o que ainda não existe lá — a produção
# tem registros nativos misturados na mesma tabela.
INCREMENTAIS="orcamentos observacoes"

mysql_local() { docker exec -i "$CONTAINER" mysql -N -u"$DB_USER" -p"$DB_PASS" "$DB_LOCAL" "$@" 2>/dev/null; }
dump_local() {
  docker exec "$CONTAINER" mysqldump -u"$DB_USER" -p"$DB_PASS" \
    --no-create-info --complete-insert --single-transaction --skip-lock-tables \
    --no-tablespaces --skip-add-locks --skip-disable-keys \
    "$@" 2>/dev/null
}

colunas() { mysql_local -e "SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB_LOCAL' AND TABLE_NAME='$1'"; }

# ─────────────────────────────────────────── assinatura de users (preflight)
#
# As tabelas incrementais carregam user_id/cliente_id/lead_id. clientes e leads vão no
# próprio pacote, então ficam consistentes por construção; `users` NÃO vai — é a tabela
# que guarda senha e foto dos beta testers e nunca é sobrescrita. Se o mapeamento
# id↔e-mail divergir entre os dois bancos, a observação gruda na pessoa errada.
# Por isso a assinatura é conferida no servidor ANTES de aplicar qualquer coisa.
ASSINATURA_LOCAL=$(mysql_local -e "SELECT SUM(CRC32(CONCAT_WS(0x7c,id,LOWER(email)))) FROM users")
IDS_USERS=$(mysql_local -e "SELECT GROUP_CONCAT(id) FROM users")

echo "==> Assinatura local de users: $ASSINATURA_LOCAL"

{
  echo "-- Pacote de sincronização gerado em $(date -Iseconds)"
  echo "SET NAMES utf8mb4;"
  echo "START TRANSACTION;"

  for t in $ESPELHO_UPSERT; do
    cols=$(colunas "$t")
    sets=$(echo "$cols" | tr ',' '\n' | grep -vx 'id' | sed 's/^\(.*\)$/\1=VALUES(\1)/' | paste -sd, -)
    echo ""
    echo "-- $t: upsert (nunca apaga linha)"
    echo "CREATE TEMPORARY TABLE _stg_$t LIKE $t;"
    dump_local "$DB_LOCAL" "$t" | grep '^INSERT INTO' | sed "s/INSERT INTO \`$t\`/INSERT INTO \`_stg_$t\`/"
    echo "INSERT INTO $t ($cols) SELECT $cols FROM _stg_$t ON DUPLICATE KEY UPDATE $sets;"
    echo "DROP TEMPORARY TABLE _stg_$t;"
  done

  echo ""
  echo "-- pedidos + itens: substituição total (só pedido_itens referencia pedidos)"
  echo "DELETE FROM pedido_itens;"
  echo "DELETE FROM pedidos;"
  for t in $ESPELHO_SUBSTITUI; do
    dump_local "$DB_LOCAL" "$t" | grep '^INSERT INTO'
  done

  for t in $INCREMENTAIS; do
    cols=$(colunas "$t" | tr ',' '\n' | grep -vx 'id' | paste -sd, -)
    echo ""
    echo "-- $t: só o que ainda não existe lá, pela chave legado_id"
    echo "CREATE TEMPORARY TABLE _stg_$t LIKE $t;"
    dump_local --where="legado_id IS NOT NULL" "$DB_LOCAL" "$t" | grep '^INSERT INTO' | sed "s/INSERT INTO \`$t\`/INSERT INTO \`_stg_$t\`/"
    # Sem o id: a produção tem registros nativos ocupando a mesma faixa de
    # auto_increment, e reaproveitar o id daqui colidiria com eles.
    echo "INSERT INTO $t ($cols) SELECT $cols FROM _stg_$t s"
    echo "  WHERE NOT EXISTS (SELECT 1 FROM $t d WHERE d.legado_id = s.legado_id);"
    echo "DROP TEMPORARY TABLE _stg_$t;"
  done

  # Os itens não podem ir por id: o orçamento acabou de ganhar um id NOVO lá dentro
  # (a produção tem orçamentos nativos ocupando a mesma faixa de auto_increment).
  # A ponte é o legado_id — daí o mapa id-local → legado_id, que é só inteiro e não
  # precisa de escape nenhum.
  itens_cols=$(colunas orcamento_itens | tr ',' '\n' | grep -vxE 'id|orcamento_id' | paste -sd, -)
  echo ""
  echo "-- itens dos orçamentos que acabaram de entrar"
  echo "CREATE TEMPORARY TABLE _map_orc (id BIGINT PRIMARY KEY, legado_id BIGINT);"
  mysql_local -e "SELECT CONCAT('INSERT INTO _map_orc VALUES (', id, ',', legado_id, ');') FROM orcamentos WHERE legado_id IS NOT NULL"
  echo "CREATE TEMPORARY TABLE _stg_orcamento_itens LIKE orcamento_itens;"
  dump_local "$DB_LOCAL" orcamento_itens | grep '^INSERT INTO' | sed 's/INSERT INTO `orcamento_itens`/INSERT INTO `_stg_orcamento_itens`/'
  echo "INSERT INTO orcamento_itens (orcamento_id,$itens_cols)"
  echo "SELECT d.id, $(echo "$itens_cols" | sed 's/[^,]*/s.&/g') FROM _stg_orcamento_itens s"
  echo "  JOIN _map_orc m ON m.id = s.orcamento_id"
  echo "  JOIN orcamentos d ON d.legado_id = m.legado_id"
  echo " WHERE NOT EXISTS (SELECT 1 FROM orcamento_itens x WHERE x.orcamento_id = d.id);"
  echo "DROP TEMPORARY TABLE _stg_orcamento_itens;"
  echo "DROP TEMPORARY TABLE _map_orc;"

  echo ""
  echo "COMMIT;"
} > "$PACOTE"

TAMANHO=$(du -h "$PACOTE" | cut -f1)
echo "==> Pacote: $PACOTE ($TAMANHO)"
echo "    statements de INSERT: $(grep -c '^INSERT INTO' "$PACOTE" || true)"

if [ "$DRY_RUN" = "1" ]; then
  echo ""
  echo "--dry-run: nada foi enviado. Inspecione o pacote acima antes de rodar de verdade."
  exit 0
fi

echo "==> Enviando para $APP_NODE"
gzip -kf "$PACOTE"
scp -i "$SSH_KEY" "$PACOTE.gz" "ubuntu@$APP_NODE:/tmp/pacote-sync.sql.gz"

echo "==> Aplicando no RDS (de dentro da VPC)"
ssh -i "$SSH_KEY" "ubuntu@$APP_NODE" "ASSINATURA_ESPERADA='$ASSINATURA_LOCAL' IDS_USERS='$IDS_USERS' bash -s" <<'REMOTO'
set -euo pipefail
cd /var/www/crm

# O .env guarda os valores entre aspas; cut sozinho traz as aspas junto e o
# mysql recusa a senha com um "Access denied" que parece problema de permissão.
val() { grep "^$1=" .env | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
DBH=$(val DB_HOST); DBU=$(val DB_USERNAME); DBP=$(val DB_PASSWORD); DBN=$(val DB_DATABASE)

ASSINATURA_PROD=$(mysql -h "$DBH" -u "$DBU" -p"$DBP" -N "$DBN" \
  -e "SELECT SUM(CRC32(CONCAT_WS(0x7c,id,LOWER(email)))) FROM users WHERE id IN ($IDS_USERS)" 2>/dev/null)

if [ "$ASSINATURA_PROD" != "$ASSINATURA_ESPERADA" ]; then
  echo "ABORTADO: o mapeamento id↔e-mail de users diverge entre os dois bancos."
  echo "  esperado (local) : $ASSINATURA_ESPERADA"
  echo "  encontrado (prod): $ASSINATURA_PROD"
  echo ""
  echo "As tabelas incrementais carregam user_id. Aplicar assim atribuiria observação"
  echo "e orçamento à pessoa errada. Reconcilie os usuários antes de sincronizar."
  exit 1
fi
echo "    assinatura de users confere ($ASSINATURA_PROD)"

gunzip -f /tmp/pacote-sync.sql.gz
time mysql -h "$DBH" -u "$DBU" -p"$DBP" "$DBN" < /tmp/pacote-sync.sql
rm -f /tmp/pacote-sync.sql

php artisan cache:clear >/dev/null
php artisan cache:aquecer 2>/dev/null || true
REMOTO

rm -f "$PACOTE" "$PACOTE.gz"
echo "=== SINCRONIZAÇÃO CONCLUÍDA ==="
