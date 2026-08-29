#!/usr/bin/env bash
#
# Cria o tópico SNS e os alarmes do CloudWatch do CRM-V2.
#
#   AWS_PROFILE=crm-v2 bash infra/monitoramento/criar-alarmes.sh
#
# É IDEMPOTENTE: `put-metric-alarm` sobrescreve o alarme de mesmo nome, então rodar de
# novo só reaplica os limiares.
#
# ⚠️ A assinatura de e-mail precisa ser CONFIRMADA no link que a AWS envia. Enquanto ela
# estiver "PendingConfirmation", os alarmes disparam e NINGUÉM recebe nada — que é
# exatamente o estado que este script existe para acabar.
#
# Por que estes alarmes e não outros: cada um corresponde a um modo de falha que já
# aconteceu ou que derrubaria o sistema sem aviso. Alarme que dispara à toa é pior que
# alarme nenhum, porque ensina a ignorar — por isso os limiares são folgados de propósito.

set -euo pipefail
export MSYS_NO_PATHCONV=1

REGIAO=sa-east-1
EMAIL=antonio.barbosa@autopel.com
TOPICO=crm-v2-alertas

ALB=app/crm-v2-alb/d55069de27f01694
TG_WEB=targetgroup/crm-v2-tg-web/04b9942a495d6f05
RDS=crm-v2-prod
REDIS=crm-v2-redis
APP1=i-0ea2c71eb633f30ed
APP2=i-09cab73e3305ca056

echo "==> Tópico SNS"
ARN_TOPICO=$(aws sns create-topic --name "$TOPICO" --region "$REGIAO" --output text --query TopicArn)
echo "    $ARN_TOPICO"

JA=$(aws sns list-subscriptions-by-topic --topic-arn "$ARN_TOPICO" --region "$REGIAO" \
       --query "Subscriptions[?Endpoint=='${EMAIL}'].SubscriptionArn" --output text)
if [ -z "$JA" ] || [ "$JA" = "None" ]; then
  aws sns subscribe --topic-arn "$ARN_TOPICO" --protocol email --notification-endpoint "$EMAIL" \
    --region "$REGIAO" > /dev/null
  echo "    assinatura criada para ${EMAIL} — CONFIRME no e-mail que a AWS acabou de enviar"
else
  echo "    assinatura: $JA"
fi

# $1 nome  $2 descrição  $3 namespace  $4 métrica  $5 estatística  $6 comparação
# $7 limiar  $8 períodos  $9 duração  $10 dado ausente  $11+ dimensões
#
# ⚠️ As dimensões vão como argumentos SEPARADOS ("Name=A,Value=1" "Name=B,Value=2"), não
# numa string única com vírgula entre os pares — o CLI recusa com "Second instance of key
# Name". Por isso elas ficam por último e são expandidas com "${@:11}".
alarme() {
  local nome="$1" desc="$2" ns="$3" metrica="$4" stat="$5" comp="$6"
  local limiar="$7" periodos="$8" duracao="$9" ausente="${10}"
  shift 10

  aws cloudwatch put-metric-alarm \
    --region "$REGIAO" \
    --alarm-name "$nome" \
    --alarm-description "$desc" \
    --namespace "$ns" \
    --metric-name "$metrica" \
    --statistic "$stat" \
    --comparison-operator "$comp" \
    --threshold "$limiar" \
    --evaluation-periods "$periodos" \
    --period "$duracao" \
    --treat-missing-data "$ausente" \
    --dimensions "$@" \
    --alarm-actions "$ARN_TOPICO" \
    --ok-actions "$ARN_TOPICO"
  echo "    $nome"
}

echo "==> Alarmes do ALB"

# Erro 500 é sempre defeito de aplicação: o usuário viu tela de erro. Um já basta.
alarme "crm-v2-5xx-aplicacao" \
  "A aplicacao respondeu 5xx. Ver storage/logs/laravel.log nos dois nos." \
  AWS/ApplicationELB HTTPCode_Target_5XX_Count Sum GreaterThanThreshold 0 1 300 \
  notBreaching "Name=LoadBalancer,Value=$ALB"

# Nó fora do rodízio: o site continua no ar pelo outro, então NADA avisa sem este alarme.
alarme "crm-v2-no-fora-do-rodizio" \
  "Um app node esta unhealthy. O ALB o tirou do rodizio; o site segue no ar pelo outro." \
  AWS/ApplicationELB UnHealthyHostCount Maximum GreaterThanOrEqualToThreshold 1 2 60 \
  notBreaching "Name=LoadBalancer,Value=$ALB" "Name=TargetGroup,Value=$TG_WEB"

# Regra de ouro nº 9: o orçamento é 400ms. 2s sustentado por 10 min é degradação real,
# não pico — limiar folgado de propósito, para não virar ruído.
alarme "crm-v2-latencia-alta" \
  "Tempo de resposta acima de 2s por 10 minutos. Orcamento da Regra 9 e 400ms." \
  AWS/ApplicationELB TargetResponseTime Average GreaterThanThreshold 2 2 300 \
  notBreaching "Name=LoadBalancer,Value=$ALB"

echo "==> Alarmes das instâncias"
for par in "app-1:$APP1" "app-2:$APP2"; do
  NOME="${par%%:*}"; ID="${par##*:}"
  alarme "crm-v2-cpu-${NOME}" \
    "CPU do ${NOME} acima de 80% por 10 minutos." \
    AWS/EC2 CPUUtilization Average GreaterThanThreshold 80 2 300 \
    notBreaching "Name=InstanceId,Value=$ID"
done

echo "==> Alarmes do RDS"

# 337MB de banco numa t4g.small (2GB): se a memória livre cair a 200MB, algo mudou muito.
alarme "crm-v2-rds-memoria" \
  "Memoria livre do RDS abaixo de 200MB. O banco tem 337MB e deveria caber inteiro em RAM." \
  AWS/RDS FreeableMemory Average LessThanThreshold 209715200 2 300 \
  notBreaching "Name=DBInstanceIdentifier,Value=$RDS"

alarme "crm-v2-rds-cpu" \
  "CPU do RDS acima de 80% por 10 minutos. Suspeitar de query sem indice." \
  AWS/RDS CPUUtilization Average GreaterThanThreshold 80 2 300 \
  notBreaching "Name=DBInstanceIdentifier,Value=$RDS"

echo "==> Alarmes do Redis"

# Evictions > 0 significa que o Redis esta descartando chave por falta de memoria.
# Com volatile-lru so cai cache (nao sessao/fila), mas ja indica que a instancia apertou.
alarme "crm-v2-redis-evictions" \
  "Redis descartando chaves por memoria. Subir a instancia; NUNCA trocar para allkeys-lru." \
  AWS/ElastiCache Evictions Sum GreaterThanThreshold 0 1 300 \
  notBreaching "Name=CacheClusterId,Value=$REDIS"

alarme "crm-v2-redis-memoria" \
  "Uso de memoria do Redis acima de 80%." \
  AWS/ElastiCache DatabaseMemoryUsagePercentage Average GreaterThanThreshold 80 2 300 \
  notBreaching "Name=CacheClusterId,Value=$REDIS"

echo ""
echo "=== ALARMES CRIADOS ==="
aws cloudwatch describe-alarms --region "$REGIAO" --alarm-name-prefix crm-v2 \
  --query 'MetricAlarms[].{Alarme:AlarmName,Estado:StateValue}' --output table

echo ""
echo "⚠️  CONFIRME a assinatura no e-mail da AWS (assunto \"AWS Notification - Subscription Confirmation\")."
echo "    Sem isso os alarmes disparam e ninguem recebe."
echo ""
echo "Ainda NAO coberto: profundidade da fila — a causa do incidente de 29/08."
echo "Depende de metrica customizada; ver infra/monitoramento/LEIA-ME.md."
