#!/usr/bin/env bash
#
# Cria a EC2 Windows do On-premises data gateway do Power BI e a rede dela.
#
#   AWS_PROFILE=crm-v2 bash infra/bi/criar-gateway.sh
#
# O que faz (IDEMPOTENTE — rodar de novo não duplica nada):
#   1. SG `crm-v2-bi-gateway`: nenhuma entrada, exceto RDP (3389) dos IPs do Tony;
#   2. regra no `crm-v2-db`: 3306 a partir do SG do gateway;
#   3. key pair `crm-v2-bi-gateway` (RSA, importada de ~/.ssh — ver abaixo);
#   4. EC2 `t3.large` Windows Server 2022, subnet pública, gp3 50 GB cifrado, IMDSv2.
#
# ⚠️ POR QUE UMA CHAVE NOVA: a `crm-v2` é ed25519, e a AWS só cifra a senha do
# Administrator do Windows com chave RSA. A chave é gerada AQUI (ssh-keygen) e só a parte
# pública sobe — a privada nunca passa pela AWS. Sem ela não há como ler a senha inicial.
#
# ⚠️ CRÉDITOS `standard`, não `unlimited`: o gateway fica ocioso quase o dia todo e só
# trabalha durante o refresh; `unlimited` cobraria cada pico acima da linha de base sem
# ninguém ver. Se o refresh ficar lento por falta de crédito, é a métrica
# `CPUCreditBalance` que mostra — e aí se decide.
#
# ⚠️ Sem Elastic IP: o gateway só faz conexões de SAÍDA (Azure Service Bus e RDS). O IP
# público serve só para o RDP, e muda se a máquina for parada — basta consultar de novo.
#
# Depois de criar: ver docs/power-bi.md §8 (instalar o gateway e o Connector/NET).

set -euo pipefail
export MSYS_NO_PATHCONV=1

REGIAO="${AWS_REGION:-sa-east-1}"
VPC=vpc-0c3d9a668fc84f036
SUBNET=subnet-0ee8892ca2716a797          # crm-v2-publica-1a
SG_DB=sg-0cbed5e7bb1f73b24               # crm-v2-db
NOME=crm-v2-bi-gateway
TIPO="${TIPO:-t3.large}"
DISCO_GB=50
# Os mesmos IPs que têm SSH nos app servers (escritório e casa do Tony).
IPS_RDP="177.118.0.135/32 177.33.25.65/32"
CHAVE_LOCAL="$HOME/.ssh/${NOME}"

aws_() { aws --region "$REGIAO" "$@"; }

# No Git Bash o aws.exe é um binário Windows e não entende /c/Users/...; com
# MSYS_NO_PATHCONV=1 ninguém converte, então a conversão é explícita.
caminho() { command -v cygpath > /dev/null && cygpath -w "$1" || echo "$1"; }

echo "==> Conta: $(aws sts get-caller-identity --query Arn --output text)"

echo "==> Chave RSA local (${CHAVE_LOCAL})"
if [[ ! -f "$CHAVE_LOCAL" ]]; then
  # -m PEM: é o formato que `get-password-data --priv-launch-key` aceita.
  ssh-keygen -q -t rsa -b 4096 -m PEM -N "" -C "$NOME" -f "$CHAVE_LOCAL"
  chmod 600 "$CHAVE_LOCAL"
  echo "    gerada"
else
  echo "    já existe"
fi

echo "==> Key pair ${NOME}"
if aws_ ec2 describe-key-pairs --key-names "$NOME" > /dev/null 2>&1; then
  echo "    já existe"
else
  aws_ ec2 import-key-pair --key-name "$NOME" \
    --public-key-material "fileb://$(caminho "${CHAVE_LOCAL}.pub")" > /dev/null
  echo "    importada"
fi

echo "==> Security group ${NOME}"
SG=$(aws_ ec2 describe-security-groups \
  --filters "Name=group-name,Values=${NOME}" "Name=vpc-id,Values=${VPC}" \
  --query 'SecurityGroups[0].GroupId' --output text)
if [[ "$SG" == "None" ]]; then
  SG=$(aws_ ec2 create-security-group --group-name "$NOME" --vpc-id "$VPC" \
    --description "Gateway do Power BI: sem entrada, exceto RDP dos IPs do Tony" \
    --query GroupId --output text)
  aws_ ec2 create-tags --resources "$SG" --tags "Key=Name,Value=${NOME}"
  echo "    criado ${SG}"
else
  echo "    já existe ${SG}"
fi

for IP in $IPS_RDP; do
  aws_ ec2 authorize-security-group-ingress --group-id "$SG" \
    --ip-permissions "IpProtocol=tcp,FromPort=3389,ToPort=3389,IpRanges=[{CidrIp=${IP},Description=RDP Tony}]" \
    > /dev/null 2>&1 && echo "    RDP liberado para ${IP}" || echo "    RDP para ${IP} já existia"
done

echo "==> crm-v2-db aceita 3306 do gateway"
aws_ ec2 authorize-security-group-ingress --group-id "$SG_DB" \
  --ip-permissions "IpProtocol=tcp,FromPort=3306,ToPort=3306,UserIdGroupPairs=[{GroupId=${SG},Description=Power BI gateway}]" \
  > /dev/null 2>&1 && echo "    regra criada" || echo "    regra já existia"

echo "==> EC2 ${NOME}"
ID=$(aws_ ec2 describe-instances \
  --filters "Name=tag:Name,Values=${NOME}" "Name=instance-state-name,Values=pending,running,stopping,stopped" \
  --query 'Reservations[0].Instances[0].InstanceId' --output text)

if [[ "$ID" == "None" ]]; then
  AMI=$(aws_ ec2 describe-images --owners amazon \
    --filters "Name=name,Values=Windows_Server-2022-English-Full-Base-*" "Name=state,Values=available" \
    --query 'sort_by(Images,&CreationDate)[-1].ImageId' --output text)
  echo "    AMI ${AMI}"

  ID=$(aws_ ec2 run-instances \
    --image-id "$AMI" \
    --instance-type "$TIPO" \
    --key-name "$NOME" \
    --subnet-id "$SUBNET" \
    --security-group-ids "$SG" \
    --associate-public-ip-address \
    --credit-specification CpuCredits=standard \
    --metadata-options "HttpTokens=required,HttpEndpoint=enabled" \
    --block-device-mappings "[{\"DeviceName\":\"/dev/sda1\",\"Ebs\":{\"VolumeSize\":${DISCO_GB},\"VolumeType\":\"gp3\",\"Encrypted\":true,\"DeleteOnTermination\":true}}]" \
    --tag-specifications "ResourceType=instance,Tags=[{Key=Name,Value=${NOME}},{Key=Projeto,Value=crm-v2}]" "ResourceType=volume,Tags=[{Key=Name,Value=${NOME}},{Key=Projeto,Value=crm-v2}]" \
    --query 'Instances[0].InstanceId' --output text)
  echo "    criada ${ID}"
else
  echo "    já existe ${ID}"
fi

aws_ ec2 wait instance-running --instance-ids "$ID"
IP=$(aws_ ec2 describe-instances --instance-ids "$ID" \
  --query 'Reservations[0].Instances[0].PublicIpAddress' --output text)

echo
echo "Pronto: ${ID} em ${IP}"
echo "A senha do Administrator leva ~5 min para ficar disponível depois do boot:"
echo "  aws ec2 get-password-data --region ${REGIAO} --instance-id ${ID} --priv-launch-key \"$(caminho "$CHAVE_LOCAL")\" --query PasswordData --output text"
echo "RDP: mstsc /v:${IP}  (usuário Administrator)"
