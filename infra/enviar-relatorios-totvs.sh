#!/usr/bin/env bash
#
# Envia os relatórios do TOTVS (a pasta "RELATORIOS TOTVS" do OneDrive do Tony) para o
# S3 do CRM-V2, de onde a produção os baixa com `totvs:sincronizar-s3`.
#
# Roda da máquina do Tony, com o `aws` CLI já configurado. Usa o perfil `crm-v2`
# (usuário IAM `crm-v2-deploy`, dedicado a este projeto) — não o perfil `default`, que
# hoje é o `licitacoes-deploy` de outro sistema. Por acaso ele também tem acesso a este
# bucket, mas depender disso seria usar a credencial errada só porque funciona.
#
# Não precisa de nada novo na AWS: o bucket já existe e o perfil `crm-v2` já tem
# permissão de escrita nele — testado antes de escrever este script.
#
# Uso:
#   bash infra/enviar-relatorios-totvs.sh
#   bash infra/enviar-relatorios-totvs.sh --dry-run    # mostra o que enviaria, sem enviar
#
# ─────────────────────────────────────────────────────────────────────────────
# ⚠️ POR QUE NÃO É `aws s3 sync` da pasta INTEIRA
#
# "RELATORIOS TOTVS" tem ~250 MB de xlsx originais, planilhas de histórico (pasta
# `Legacy/`) e arquivos avulsos (`Arquivos aversos/`) que não são os relatórios que os
# importadores leem. Só os CSVs em `CSV/` e `Pedidos emitidos/` importam — o resto seria
# custo de armazenamento e tempo de upload à toa.
#
# ⚠️ A ESTRUTURA DE PASTAS TEM QUE SOBREVIVER À VIAGEM. `config/totvs.php` resolve cada
# domínio por um padrão de caminho relativo (`CSV/Clientes - SQL.csv`,
# `Pedidos emitidos/Pedidos emitidos*SQL.csv`) — os MESMOS padrões valem local e em
# produção. Por isso o prefixo do S3 é `totvs/` seguido da mesma subpasta de origem
# (`totvs/CSV/...`, `totvs/Pedidos emitidos/...`): mudar essa estrutura sem mudar o
# config faz o import não achar nada, silenciosamente.
#
# ⚠️ `--include "CSV/*.csv"` TAMBÉM CASA SUBPASTA — o `*` do aws-cli atravessa `/`, não é
# glob de shell. Sem o `--exclude "CSV/etc/*"`, o extrato manual que mora em
# `CSV/etc/carteira (...).csv` (nada a ver com relatório do TOTVS) subiria junto.
# ─────────────────────────────────────────────────────────────────────────────

set -euo pipefail

export AWS_PROFILE="${AWS_PROFILE:-crm-v2}"

ORIGEM="${TOTVS_RELATORIOS_PATH:-C:/Users/antonio.barbosa/OneDrive - autopel.com/RELATORIOS TOTVS}"
BUCKET="s3://crm-v2-arquivos-890615325644/totvs"

DRY_RUN=""
[ "${1:-}" = "--dry-run" ] && DRY_RUN="--dryrun"

echo "==> Perfil AWS: $AWS_PROFILE ($(aws sts get-caller-identity --query Arn --output text 2>/dev/null || echo 'não autenticado'))"
echo "==> Origem: $ORIGEM"
echo "==> Destino: $BUCKET"
echo ""

aws s3 sync "$ORIGEM" "$BUCKET" $DRY_RUN \
  --exclude "*" \
  --include "CSV/*.csv" \
  --include "Pedidos emitidos/*.csv" \
  --exclude "CSV/etc/*" \
  --delete

echo ""
echo "=== CONCLUÍDO ==="
echo "Na produção, rode: php artisan totvs:sincronizar-s3"
