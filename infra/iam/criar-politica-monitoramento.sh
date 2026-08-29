#!/usr/bin/env bash
#
# Dá ao CRM-V2 as permissões de monitoramento que faltam.
#
# ⚠️ Rodar com um profile ADMIN — o `crm-v2-deploy` não tem permissão de IAM, de propósito.
#
#   PowerShell:
#     & "$env:LOCALAPPDATA\Programs\Git\bin\bash.exe" -c "AWS_PROFILE=default bash infra/iam/criar-politica-monitoramento.sh"
#
# É IDEMPOTENTE.
#
# Anexa a MESMA política a dois principais diferentes, porque os dois precisam dela por
# motivos distintos:
#
#   - usuário `crm-v2-deploy` → criar o tópico SNS e a assinatura de e-mail dos alarmes
#     (o CloudWatch em si ele já podia; só o SNS faltava)
#   - role `crm-v2-app` (as EC2) → publicar métrica customizada, para a profundidade da
#     fila virar alarme. Foi exatamente o sinal que faltou no incidente de 29/08, quando a
#     fila ficou 6 horas travada sem ninguém saber.
#
# ⚠️ `cloudwatch:PutMetricData` não aceita recurso específico — não existe ARN de métrica.
# Por isso o escopo vem da condição `cloudwatch:namespace`, travada em "CRM-V2": a
# aplicação não consegue escrever em nenhum namespace da AWS nem de outro sistema.

set -euo pipefail
export MSYS_NO_PATHCONV=1

CONTA=890615325644
POLITICA=crm-v2-monitoramento
USUARIO=crm-v2-deploy
ROLE=crm-v2-app
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ARN_POL="arn:aws:iam::${CONTA}:policy/${POLITICA}"

echo "==> Conferindo que o profile atual é admin"
aws sts get-caller-identity --query Arn --output text
aws iam list-roles --max-items 1 > /dev/null 2>&1 || {
  echo "ERRO: este profile não enxerga IAM. Use o admin: AWS_PROFILE=default bash $0"
  exit 1
}

echo "==> Política ${POLITICA}"
if aws iam get-policy --policy-arn "$ARN_POL" > /dev/null 2>&1; then
  echo "    já existe — publicando nova versão"
  for V in $(aws iam list-policy-versions --policy-arn "$ARN_POL" \
               --query 'Versions[?IsDefaultVersion==`false`].VersionId' --output text); do
    aws iam delete-policy-version --policy-arn "$ARN_POL" --version-id "$V" || true
  done
  aws iam create-policy-version --policy-arn "$ARN_POL" \
    --policy-document "$(cat "${DIR}/politica-monitoramento.json")" --set-as-default > /dev/null
else
  # Conteúdo inline, não `file://`: no Git Bash o caminho vira POSIX (/c/Users/...) e o
  # aws.exe nativo do Windows não o entende. Ver infra/iam/criar-role-s3.sh.
  aws iam create-policy --policy-name "$POLITICA" \
    --policy-document "$(cat "${DIR}/politica-monitoramento.json")" \
    --description "SNS de alertas e metrica customizada do CRM-V2" > /dev/null
  echo "    criada"
fi

echo "==> Anexando ao usuário ${USUARIO}"
aws iam attach-user-policy --user-name "$USUARIO" --policy-arn "$ARN_POL"
echo "    ok"

echo "==> Anexando à role ${ROLE}"
aws iam attach-role-policy --role-name "$ROLE" --policy-arn "$ARN_POL"
echo "    ok"

echo ""
echo "=== PRONTO ==="
echo "Próximo passo (NÃO precisa de admin):"
echo "  AWS_PROFILE=crm-v2 bash infra/monitoramento/criar-alarmes.sh"
