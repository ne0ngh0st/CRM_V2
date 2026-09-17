#!/usr/bin/env bash
#
# Liga e desliga a EC2 do gateway do Power BI em horário fixo (EventBridge Scheduler).
#
#   AWS_PROFILE=default bash infra/bi/agendar-gateway.sh
#
# ⚠️ Perfil ADMIN: cria uma role do IAM, que o `crm-v2-deploy` não pode criar (mesmo
# motivo do infra/iam/criar-role-s3.sh).
#
# POR QUE AGENDADO E NÃO LIGADO 24 H (decisão do Tony, 2026-09-17): a `t3.large` Windows
# ligada o tempo todo custaria ~R$ 600/mês para ficar ociosa quase o dia inteiro. Nas
# janelas abaixo são ~2h20 por dia útil. Consequência: o refresh deixa de ser disparado
# pelo CRM logo depois da importação (o gateway estaria desligado) e passa a ser o
# REFRESH AGENDADO do Power BI, dentro das janelas. POWERBI_REFRESH_HABILITADO fica false.
#
# Janelas (segunda a sexta, horário de São Paulo):
#   refresh 10:30 → liga 10:10, desliga 11:20
#   refresh 13:30 → liga 13:10, desliga 14:20
# ⚠️ Meia hora DEPOIS da hora cheia de propósito: o `totvs:atualizar` roda no minuto 0 e
# leva ~2 min; refresh no mesmo minuto leria o faturamento no meio da importação.
# 20 min antes: o Windows sobe e o serviço do gateway fica online. 50 min depois: folga
# para a carga do faturamento terminar — desligar no meio faz o refresh falhar.
#
# ⚠️ Mudou o horário do refresh no Power BI? Mude as janelas AQUI também e rode de novo
# (o script atualiza os schedules existentes).
#
# É IDEMPOTENTE.

set -euo pipefail
export MSYS_NO_PATHCONV=1

REGIAO="${AWS_REGION:-sa-east-1}"
CONTA=890615325644
NOME=crm-v2-bi-gateway
ROLE=crm-v2-bi-gateway-agenda
FUSO=America/Sao_Paulo

# nome do schedule | ação | hora | minuto
JANELAS="
liga-1010|start|10|10
desliga-1120|stop|11|20
liga-1310|start|13|10
desliga-1420|stop|14|20
"

aws_() { aws --region "$REGIAO" "$@"; }

echo "==> Conta: $(aws sts get-caller-identity --query Arn --output text)"
aws iam list-roles --max-items 1 > /dev/null 2>&1 || {
  echo "ERRO: este perfil não enxerga IAM. Use: AWS_PROFILE=default bash $0"
  exit 1
}

ID=$(aws_ ec2 describe-instances \
  --filters "Name=tag:Name,Values=${NOME}" "Name=instance-state-name,Values=pending,running,stopping,stopped" \
  --query 'Reservations[0].Instances[0].InstanceId' --output text)
[[ "$ID" != "None" ]] || { echo "ERRO: EC2 ${NOME} não existe (rode infra/bi/criar-gateway.sh)"; exit 1; }
echo "==> Instância ${ID}"

CONFIANCA=$(cat <<EOF
{
  "Version": "2012-10-17",
  "Statement": [{
    "Effect": "Allow",
    "Principal": {"Service": "scheduler.amazonaws.com"},
    "Action": "sts:AssumeRole",
    "Condition": {"StringEquals": {"aws:SourceAccount": "${CONTA}"}}
  }]
}
EOF
)

# Só ESTA instância. kms:CreateGrant é o que o EC2 pede para ligar máquina com disco
# cifrado; a condição ViaService impede o uso da permissão fora do EC2.
POLITICA=$(cat <<EOF
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": ["ec2:StartInstances", "ec2:StopInstances"],
      "Resource": "arn:aws:ec2:${REGIAO}:${CONTA}:instance/${ID}"
    },
    {
      "Effect": "Allow",
      "Action": "kms:CreateGrant",
      "Resource": "*",
      "Condition": {
        "StringEquals": {"kms:ViaService": "ec2.${REGIAO}.amazonaws.com"},
        "Bool": {"kms:GrantIsForAWSResource": "true"}
      }
    }
  ]
}
EOF
)

echo "==> Role ${ROLE}"
if aws iam get-role --role-name "$ROLE" > /dev/null 2>&1; then
  aws iam update-assume-role-policy --role-name "$ROLE" --policy-document "$CONFIANCA"
  echo "    já existe (confiança atualizada)"
else
  aws iam create-role --role-name "$ROLE" --assume-role-policy-document "$CONFIANCA" \
    --description "EventBridge Scheduler liga/desliga o gateway do Power BI" > /dev/null
  echo "    criada"
fi
aws iam put-role-policy --role-name "$ROLE" --policy-name liga-desliga-gateway --policy-document "$POLITICA"
ROLE_ARN="arn:aws:iam::${CONTA}:role/${ROLE}"

# A role recém-criada leva alguns segundos para poder ser assumida.
sleep 10

echo "==> Schedules (${FUSO}, segunda a sexta)"
while IFS='|' read -r SUFIXO ACAO HORA MINUTO; do
  [[ -n "$SUFIXO" ]] || continue
  NOME_S="${NOME}-${SUFIXO}"
  [[ "$ACAO" == "start" ]] && API=startInstances || API=stopInstances

  # ⚠️ Retentativa CURTA (15 min): o padrão do Scheduler é tentar por até 24 h, e um
  # "ligar" das 10:10 que só passasse de madrugada deixaria a máquina ligada à toa.
  ALVO=$(cat <<EOF
{
  "Arn": "arn:aws:scheduler:::aws-sdk:ec2:${API}",
  "RoleArn": "${ROLE_ARN}",
  "Input": "{\"InstanceIds\":[\"${ID}\"]}",
  "RetryPolicy": {"MaximumEventAgeInSeconds": 900, "MaximumRetryAttempts": 3}
}
EOF
)

  ARGS=(--name "$NOME_S"
        --schedule-expression "cron(${MINUTO} ${HORA} ? * MON-FRI *)"
        --schedule-expression-timezone "$FUSO"
        --flexible-time-window Mode=OFF
        --state ENABLED
        --target "$ALVO")

  if aws_ scheduler get-schedule --name "$NOME_S" > /dev/null 2>&1; then
    aws_ scheduler update-schedule "${ARGS[@]}" > /dev/null
    echo "    ${NOME_S} (${HORA}:${MINUTO}, ${ACAO}) atualizado"
  else
    aws_ scheduler create-schedule "${ARGS[@]}" \
      --description "Gateway do Power BI: ${ACAO} às ${HORA}:${MINUTO} (seg-sex)" > /dev/null
    echo "    ${NOME_S} (${HORA}:${MINUTO}, ${ACAO}) criado"
  fi
done <<< "$JANELAS"

echo "==> Schedules antigos (nomes fora da lista acima)"
ATUAIS=$(echo "$JANELAS" | cut -d'|' -f1 | sed "/^$/d; s/^/${NOME}-/")
for S in $(aws_ scheduler list-schedules --name-prefix "${NOME}-" --query 'Schedules[].Name' --output text); do
  grep -qx "$S" <<< "$ATUAIS" || { aws_ scheduler delete-schedule --name "$S"; echo "    ${S} removido"; }
done

echo
aws_ scheduler list-schedules --name-prefix "$NOME" \
  --query 'Schedules[].[Name,State]' --output table
