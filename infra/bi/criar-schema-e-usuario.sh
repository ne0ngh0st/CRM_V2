#!/usr/bin/env bash
#
# Cria, no RDS, o schema do Power BI e o usuário só-leitura que o gateway usa.
#
# ⚠️ Rodar com a credencial MASTER do RDS, de DENTRO de um nó do app (o RDS só aceita
# conexão das EC2 do app — ver docs/deploy-aws.md).
#
# ⚠️ No RDS de produção o master É o `palma`, o mesmo usuário do app ("managed master user
# password", senha rotacionada pelo Secrets Manager a cada 7 dias). Por isso, sem
# MASTER_USER/MASTER_SENHA no ambiente, o script usa DB_USERNAME/DB_PASSWORD do .env do
# próprio nó — que é a senha vigente, senão o CRM estaria fora do ar. Mesmo assim o schema
# fica FORA das migrations: localmente o `palma` não tem CREATE global, e criar usuário de
# banco não é trabalho de deploy.
#
#   ssh -i ~/.ssh/crm-v2 ubuntu@15.229.96.223
#   cd /var/www/crm && bash infra/bi/criar-schema-e-usuario.sh
#
# ⚠️ ORDEM NO DEPLOY: este script roda ANTES do `migrate` da branch que traz o BI. A
# migration `2026_09_16_110000` para com erro se o schema não existir.
#
# É IDEMPOTENTE: rodar de novo não duplica nada. A senha do `bi_leitura` só é trocada
# com ROTACIONAR=1 — sem isso, rodar de novo não derruba o gateway que já a usa.
#
# O que ele faz:
#   1. CREATE DATABASE bi (utf8mb4_unicode_ci, a mesma collation das tabelas do app);
#   2. ALL PRIVILEGES em bi.* para o usuário do app (as migrations criam tabelas e views lá);
#   3. usuário bi_leitura: SELECT em bi.* e SELECT nas tabelas do app que as views leem.
#
# Por que o bi_leitura precisa de SELECT nas tabelas do app: as views são
# `SQL SECURITY INVOKER` — rodam com a permissão de QUEM CONSULTA, não de quem criou.
# É o que evita o problema do legado, em que a view ficava presa ao DEFINER.

set -euo pipefail

RDS_HOST="${RDS_HOST:-crm-v2-prod.c3mguim6agp4.sa-east-1.rds.amazonaws.com}"
BANCO_APP="${BANCO_APP:-palma_v2}"
USUARIO_APP="${USUARIO_APP:-palma}"
SCHEMA_BI="${SCHEMA_BI:-bi}"
USUARIO_BI="${USUARIO_BI:-bi_leitura}"
ROTACIONAR="${ROTACIONAR:-0}"
ENV_APP="${ENV_APP:-/var/www/crm/.env}"

# ⚠️ Espelho de App\Services\PowerBi\SchemaBi::TABELAS_DO_APP. O SchemaBiTest
# compara as duas listas e falha se divergirem — view nova lendo tabela nova sem
# este grant só daria erro no primeiro refresh do Power BI.
TABELAS_DO_APP="clientes faturamentos grupos_cliente leads metas_mensais model_has_roles orcamentos pedido_itens pedidos produtos roles segmentos users vendedor_perfis vendedores_totvs"

# Lê uma chave do .env sem executá-lo (o valor pode vir entre aspas).
do_env() {
  [[ -r "$ENV_APP" ]] || return 0
  grep -E "^$1=" "$ENV_APP" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'
}

MASTER_USER="${MASTER_USER:-$(do_env DB_USERNAME)}"
: "${MASTER_USER:?defina MASTER_USER (nao achei DB_USERNAME em $ENV_APP)}"

# Identificadores entram crus no SQL: só letras, dígitos e sublinhado.
for NOME in "$BANCO_APP" "$USUARIO_APP" "$SCHEMA_BI" "$USUARIO_BI" $TABELAS_DO_APP; do
  [[ "$NOME" =~ ^[A-Za-z0-9_]+$ ]] || { echo "ERRO: identificador invalido: $NOME"; exit 1; }
done

command -v mysql > /dev/null || { echo "ERRO: cliente mysql nao instalado neste no"; exit 1; }

MASTER_SENHA="${MASTER_SENHA:-$(do_env DB_PASSWORD)}"

if [[ -z "$MASTER_SENHA" ]]; then
  read -r -s -p "Senha do master (${MASTER_USER}): " MASTER_SENHA
  echo
fi

# MYSQL_PWD em vez de -p<senha>: a senha não aparece no `ps` nem no histórico.
sql() {
  MYSQL_PWD="$MASTER_SENHA" mysql --host="$RDS_HOST" --user="$MASTER_USER" \
    --ssl-mode=REQUIRED --batch --skip-column-names -e "$1"
}

echo "==> Conectando em ${RDS_HOST}"
sql "SELECT CONCAT('    ', CURRENT_USER(), ' em MySQL ', VERSION())"

echo "==> Schema ${SCHEMA_BI}"
sql "CREATE DATABASE IF NOT EXISTS \`${SCHEMA_BI}\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

# Desnecessário quando o app É o master (produção hoje); vale se um dia deixar de ser.
if [[ "$USUARIO_APP" != "$MASTER_USER" ]]; then
  echo "==> Permissão do app (${USUARIO_APP}) em ${SCHEMA_BI}.*"
  sql "GRANT ALL PRIVILEGES ON \`${SCHEMA_BI}\`.* TO '${USUARIO_APP}'@'%'"
fi

echo "==> Usuário ${USUARIO_BI}"
EXISTE=$(sql "SELECT COUNT(*) FROM mysql.user WHERE user = '${USUARIO_BI}'")

if [[ "$EXISTE" == "0" || "$ROTACIONAR" == "1" ]]; then
  # Gerada aqui e mostrada UMA vez: vai para a fonte de dados do gateway, não para disco.
  SENHA_BI=$(openssl rand -base64 30 | tr -dc 'A-Za-z0-9' | head -c 32)

  if [[ "$EXISTE" == "0" ]]; then
    # ⚠️ REQUIRE SSL: a conexão sai do gateway pela rede da VPC, mas nada custa exigir
    # criptografia. O MySQL Connector/NET usa SSL por padrão (SslMode=Preferred).
    # MAX_USER_CONNECTIONS limita o estrago de um refresh mal configurado no RDS.
    sql "CREATE USER '${USUARIO_BI}'@'%' IDENTIFIED BY '${SENHA_BI}' REQUIRE SSL WITH MAX_USER_CONNECTIONS 5"
    echo "    criado"
  else
    sql "ALTER USER '${USUARIO_BI}'@'%' IDENTIFIED BY '${SENHA_BI}'"
    echo "    senha rotacionada — atualize a fonte de dados do gateway"
  fi

  echo
  echo "    ┌──────────────────────────────────────────────────────────────"
  echo "    │ senha do ${USUARIO_BI}: ${SENHA_BI}"
  echo "    │ guarde no cofre agora; ela não é exibida de novo"
  echo "    └──────────────────────────────────────────────────────────────"
  echo
else
  echo "    já existe — senha mantida (ROTACIONAR=1 para trocar)"
fi

echo "==> SELECT em ${SCHEMA_BI}.*"
sql "GRANT SELECT ON \`${SCHEMA_BI}\`.* TO '${USUARIO_BI}'@'%'"

echo "==> SELECT nas tabelas do app que as views leem"
for TABELA in $TABELAS_DO_APP; do
  sql "GRANT SELECT ON \`${BANCO_APP}\`.\`${TABELA}\` TO '${USUARIO_BI}'@'%'"
  echo "    ${BANCO_APP}.${TABELA}"
done

echo
echo "==> Permissões finais do ${USUARIO_BI}"
sql "SHOW GRANTS FOR '${USUARIO_BI}'@'%'" | sed 's/^/    /'

echo
echo "Pronto. Próximos passos:"
echo "  1. deploy da branch (as migrations criam as tabelas e as views em ${SCHEMA_BI});"
echo "  2. php artisan bi:carregar-referencias --force"
