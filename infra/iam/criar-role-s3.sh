#!/usr/bin/env bash
#
# Cria a IAM role que dá às EC2 do CRM-V2 acesso ao bucket de arquivos — e SÓ a ele.
#
# ⚠️ Rodar com um profile ADMIN. O `crm-v2-deploy` não tem permissão de IAM, de propósito
# (é o isolamento descrito em docs/deploy-aws.md §4.0). Este é um dos poucos momentos em
# que o profile admin é a ferramenta certa.
#
#   AWS_PROFILE=default bash infra/iam/criar-role-s3.sh
#
# É IDEMPOTENTE: rodar de novo não duplica nada.
#
# Por que role e não usuário com chave: a credencial de uma role é entregue pelo metadata
# service da instância, rotaciona sozinha e NUNCA fica escrita em disco. Um `.env` vazado
# não leva junto o acesso ao S3.

set -euo pipefail

# ⚠️ No Git Bash do Windows, o MSYS reescreve argumentos que parecem caminho POSIX antes
# de entregá-los ao aws.exe — `Name=crm-v2-app` sobrevive, mas qualquer coisa iniciada por
# `/` vira `C:/Program Files/Git/...`. Desligar é mais barato que auditar cada argumento.
export MSYS_NO_PATHCONV=1

CONTA=890615325644
ROLE=crm-v2-app
POLITICA=crm-v2-s3
PERFIL_INSTANCIA=crm-v2-app
INSTANCIAS="i-0ea2c71eb633f30ed i-09cab73e3305ca056"
REGIAO=sa-east-1
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "==> Conferindo que o profile atual é admin"
QUEM=$(aws sts get-caller-identity --query Arn --output text)
echo "    $QUEM"
aws iam list-roles --max-items 1 > /dev/null 2>&1 || {
  echo "ERRO: este profile não enxerga IAM. Use o profile admin:"
  echo "      AWS_PROFILE=default bash $0"
  exit 1
}

echo "==> Política ${POLITICA}"
ARN_POL="arn:aws:iam::${CONTA}:policy/${POLITICA}"
if aws iam get-policy --policy-arn "$ARN_POL" > /dev/null 2>&1; then
  # ⚠️ "já existe" NÃO pode significar "não faço nada": se o JSON mudou (foi o caso quando
  # descobrimos que os prefixos reais são facas/, perfis/ e exports/, não uploads/), pular
  # aqui deixaria a política velha valendo e a correção não chegaria a lugar nenhum.
  echo "    já existe — publicando nova versão"
  # A AWS guarda no máximo 5 versões por política; a partir da 6ª o create falha. Apagar as
  # não-default antes mantém o script rodando indefinidamente.
  for V in $(aws iam list-policy-versions --policy-arn "$ARN_POL" \
               --query 'Versions[?IsDefaultVersion==`false`].VersionId' --output text); do
    aws iam delete-policy-version --policy-arn "$ARN_POL" --version-id "$V" || true
  done
  aws iam create-policy-version \
    --policy-arn "$ARN_POL" \
    --policy-document "$(cat "${DIR}/politica-s3-app.json")" \
    --set-as-default > /dev/null
  echo "    versão publicada"
else
  # ⚠️ Conteúdo inline, não `file://${DIR}/...`. No Git Bash do Windows o $DIR é um caminho
  # POSIX (/c/Users/...) e o `aws` é um executável nativo do Windows, que não o entende —
  # o erro fala de JSON inválido, sem dizer que o problema foi o caminho.
  aws iam create-policy \
    --policy-name "$POLITICA" \
    --policy-document "$(cat "${DIR}/politica-s3-app.json")" \
    --description "Acesso do CRM-V2 aos prefixos uploads/ e exports/ do bucket de arquivos" \
    > /dev/null
  echo "    criada"
fi

echo "==> Role ${ROLE}"
if aws iam get-role --role-name "$ROLE" > /dev/null 2>&1; then
  echo "    já existe"
else
  aws iam create-role \
    --role-name "$ROLE" \
    --assume-role-policy-document "$(cat "${DIR}/confianca-ec2.json")" \
    --description "Role das EC2 do CRM-V2" \
    > /dev/null
  echo "    criada"
fi

aws iam attach-role-policy --role-name "$ROLE" --policy-arn "$ARN_POL"
echo "    política anexada"

echo "==> Instance profile ${PERFIL_INSTANCIA}"
if aws iam get-instance-profile --instance-profile-name "$PERFIL_INSTANCIA" > /dev/null 2>&1; then
  echo "    já existe"
else
  aws iam create-instance-profile --instance-profile-name "$PERFIL_INSTANCIA" > /dev/null
  echo "    criado"
fi

# ⚠️ Uma role por instance profile. Adicionar de novo dá LimitExceeded, não "já existe" —
# por isso o teste antes, e não um `|| true` que engoliria erro de verdade.
JA=$(aws iam get-instance-profile --instance-profile-name "$PERFIL_INSTANCIA" \
       --query 'InstanceProfile.Roles[0].RoleName' --output text 2>/dev/null || echo "None")
if [ "$JA" = "$ROLE" ]; then
  echo "    role já vinculada"
else
  aws iam add-role-to-instance-profile \
    --instance-profile-name "$PERFIL_INSTANCIA" --role-name "$ROLE"
  echo "    role vinculada"
  # A propagação do IAM não é instantânea; associar antes disso falha com
  # "Invalid IAM Instance Profile name".
  echo "    aguardando propagação (10s)"
  sleep 10
fi

echo "==> Associando às instâncias"
for ID in $INSTANCIAS; do
  ATUAL=$(aws ec2 describe-iam-instance-profile-associations --region "$REGIAO" \
            --filters "Name=instance-id,Values=${ID}" \
            --query 'IamInstanceProfileAssociations[?State!=`disassociated`].AssociationId' \
            --output text)
  if [ -n "$ATUAL" ] && [ "$ATUAL" != "None" ]; then
    echo "    $ID já tem instance profile associado — pulando"
    continue
  fi
  aws ec2 associate-iam-instance-profile --region "$REGIAO" \
    --instance-id "$ID" \
    --iam-instance-profile "Name=${PERFIL_INSTANCIA}" > /dev/null
  echo "    $ID associado"
done

echo ""
echo "=== ROLE PRONTA ==="
echo "Próximo passo (NÃO precisa de admin):"
echo "  bash infra/ativar-s3.sh"
