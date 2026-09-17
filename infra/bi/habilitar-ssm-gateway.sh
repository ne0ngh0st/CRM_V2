#!/usr/bin/env bash
#
# Dá à EC2 do gateway do Power BI a permissão do AWS Systems Manager (SSM).
#
#   AWS_PROFILE=default bash infra/bi/habilitar-ssm-gateway.sh
#
# ⚠️ Perfil ADMIN: cria role do IAM e associa instance profile (o `crm-v2-deploy` não pode).
#
# POR QUE: com o SSM dá para rodar PowerShell na máquina sem RDP — instalar o gateway e o
# Connector/NET, conferir o serviço, ler log. O agente do SSM já vem na AMI do Windows; só
# faltava a credencial. A role tem SÓ a política gerenciada `AmazonSSMManagedInstanceCore`,
# nada de S3/RDS: a máquina não precisa falar com a AWS para mais nada.
#
# É IDEMPOTENTE.

set -euo pipefail
export MSYS_NO_PATHCONV=1

REGIAO="${AWS_REGION:-sa-east-1}"
NOME=crm-v2-bi-gateway
ROLE=crm-v2-bi-gateway-ec2
POLITICA=arn:aws:iam::aws:policy/AmazonSSMManagedInstanceCore

aws iam list-roles --max-items 1 > /dev/null 2>&1 || {
  echo "ERRO: este perfil não enxerga IAM. Use: AWS_PROFILE=default bash $0"
  exit 1
}

ID=$(aws ec2 describe-instances --region "$REGIAO" \
  --filters "Name=tag:Name,Values=${NOME}" "Name=instance-state-name,Values=pending,running,stopping,stopped" \
  --query 'Reservations[0].Instances[0].InstanceId' --output text)
[[ "$ID" != "None" ]] || { echo "ERRO: EC2 ${NOME} não existe"; exit 1; }
echo "==> Instância ${ID}"

echo "==> Role ${ROLE}"
if aws iam get-role --role-name "$ROLE" > /dev/null 2>&1; then
  echo "    já existe"
else
  aws iam create-role --role-name "$ROLE" \
    --description "EC2 do gateway do Power BI: só o agente do SSM" \
    --assume-role-policy-document '{"Version":"2012-10-17","Statement":[{"Effect":"Allow","Principal":{"Service":"ec2.amazonaws.com"},"Action":"sts:AssumeRole"}]}' \
    > /dev/null
  echo "    criada"
fi
aws iam attach-role-policy --role-name "$ROLE" --policy-arn "$POLITICA"

echo "==> Instance profile ${ROLE}"
if ! aws iam get-instance-profile --instance-profile-name "$ROLE" > /dev/null 2>&1; then
  aws iam create-instance-profile --instance-profile-name "$ROLE" > /dev/null
  aws iam add-role-to-instance-profile --instance-profile-name "$ROLE" --role-name "$ROLE"
  echo "    criado"
  # O profile recém-criado leva alguns segundos para poder ser associado.
  sleep 15
else
  echo "    já existe"
fi

echo "==> Associação com a instância"
ATUAL=$(aws ec2 describe-iam-instance-profile-associations --region "$REGIAO" \
  --filters "Name=instance-id,Values=${ID}" "Name=state,Values=associated,associating" \
  --query 'IamInstanceProfileAssociations[0].IamInstanceProfile.Arn' --output text)
if [[ "$ATUAL" == "None" ]]; then
  aws ec2 associate-iam-instance-profile --region "$REGIAO" \
    --instance-id "$ID" --iam-instance-profile "Name=${ROLE}" > /dev/null
  echo "    associado"
else
  echo "    já associado (${ATUAL})"
fi

echo
echo "O agente do SSM pega a credencial nova em alguns minutos. Para conferir:"
echo "  aws ssm describe-instance-information --region ${REGIAO} --filters Key=InstanceIds,Values=${ID}"
