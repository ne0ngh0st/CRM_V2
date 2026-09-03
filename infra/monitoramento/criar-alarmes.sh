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
TG_REVERB=targetgroup/crm-v2-tg-reverb/df4bca2e4958329d
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
# $7 limiar  $8 períodos  $9 duração  $10 dado ausente  $11 pontos  $12+ dimensões
#
# ⚠️ As dimensões vão como argumentos SEPARADOS ("Name=A,Value=1" "Name=B,Value=2"), não
# numa string única com vírgula entre os pares — o CLI recusa com "Second instance of key
# Name". Por isso elas ficam por último e são expandidas com "$@" depois do shift.
#
# $11 ("pontos") é o M de "M de N datapoints": quantos dos $8 períodos precisam violar o
# limiar para disparar. "-" = padrão da AWS (todos os $8, consecutivos).
# Existe por causa do rds-memoria: com M=N o alarme acompanha a métrica de perto demais
# e, quando ela passeia EM CIMA do limiar, fica oscilando — foram 17 e-mails em 7 dias,
# 12 deles em 18 horas, sem nada estar acontecendo.
alarme() {
  local nome="$1" desc="$2" ns="$3" metrica="$4" stat="$5" comp="$6"
  local limiar="$7" periodos="$8" duracao="$9" ausente="${10}" pontos="${11}"
  shift 11

  # datapoints igual a evaluation-periods É o comportamento padrão, então dá para sempre
  # mandar a flag em vez de montar array condicional (que quebra com `set -u` vazio).
  if [ "$pontos" = "-" ]; then pontos="$periodos"; fi

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
    --datapoints-to-alarm "$pontos" \
    --period "$duracao" \
    --treat-missing-data "$ausente" \
    --dimensions "$@" \
    --alarm-actions "$ARN_TOPICO" \
    --ok-actions "$ARN_TOPICO"
  echo "    $nome"
}

echo "==> Alarmes do ALB"

# Erro 500 é sempre defeito de aplicação: o usuário viu tela de erro. Um já basta.
#
# ⚠️ ESCOPADO AO TARGET GROUP WEB de propósito. Sem a dimensão TargetGroup ele soma os
# dois grupos, e o do Reverb (websocket) devolve 5xx em queda de conexão sem que ninguém
# tenha visto tela de erro. Em 72h de 2026-09-03: 12 respostas 5xx, TODAS do Reverb e
# ZERO do web — cada uma virando e-mail que mandava olhar o laravel.log, onde não havia
# nada. Se voltar a somar os dois grupos, o alarme volta a mentir sobre o que aconteceu.
alarme "crm-v2-5xx-aplicacao" \
  "A aplicacao respondeu 5xx para um usuario. Ver storage/logs/laravel.log nos dois nos." \
  AWS/ApplicationELB HTTPCode_Target_5XX_Count Sum GreaterThanThreshold 0 1 300 \
  notBreaching - "Name=LoadBalancer,Value=$ALB" "Name=TargetGroup,Value=$TG_WEB"

# Reverb é websocket: conexão que cai no meio vira 5xx sem ninguém ter visto erro, e só
# o app-1 serve esse target group. Um evento isolado não é notícia; cinco em cinco
# minutos é o sino em tempo real parando de funcionar.
alarme "crm-v2-5xx-reverb" \
  "5 ou mais 5xx do Reverb em 5 min. Afeta o sino em tempo real; o resto do site nao. Ver supervisorctl status crm-reverb no app-1." \
  AWS/ApplicationELB HTTPCode_Target_5XX_Count Sum GreaterThanOrEqualToThreshold 5 1 300 \
  notBreaching - "Name=LoadBalancer,Value=$ALB" "Name=TargetGroup,Value=$TG_REVERB"

# Nó fora do rodízio: o site continua no ar pelo outro, então NADA avisa sem este alarme.
alarme "crm-v2-no-fora-do-rodizio" \
  "Um app node esta unhealthy. O ALB o tirou do rodizio; o site segue no ar pelo outro." \
  AWS/ApplicationELB UnHealthyHostCount Maximum GreaterThanOrEqualToThreshold 1 2 60 \
  notBreaching - "Name=LoadBalancer,Value=$ALB" "Name=TargetGroup,Value=$TG_WEB"

# Regra de ouro nº 9: o orçamento é 400ms. 2s sustentado por 10 min é degradação real,
# não pico — limiar folgado de propósito, para não virar ruído.
alarme "crm-v2-latencia-alta" \
  "Tempo de resposta acima de 2s por 10 minutos. Orcamento da Regra 9 e 400ms." \
  AWS/ApplicationELB TargetResponseTime Average GreaterThanThreshold 2 2 300 \
  notBreaching - "Name=LoadBalancer,Value=$ALB"

echo "==> Alarmes das instâncias"
for par in "app-1:$APP1" "app-2:$APP2"; do
  NOME="${par%%:*}"; ID="${par##*:}"
  alarme "crm-v2-cpu-${NOME}" \
    "CPU do ${NOME} acima de 80% por 10 minutos." \
    AWS/EC2 CPUUtilization Average GreaterThanThreshold 80 2 300 \
    notBreaching - "Name=InstanceId,Value=$ID"
done

echo "==> Alarmes do RDS"

# ⚠️ O comentário aqui dizia "337MB de banco numa t4g.small (2GB)". Isso deixou de valer
# em 2026-08-31, com a carga do histórico de faturamento: o banco tem 1,66GB.
#
# O limiar de 150MB e o "3 de 5" não são folga arbitrária: saíram de simular as duas
# regras sobre a série real de 5 dias (1.440 datapoints de 5 min), em 2026-09-03.
#
#   200MB, 2 de 2 (antigo) -> 9 disparos    150MB, 3 de 5 (novo) -> 3 disparos
#
# ⚠️ NÃO acreditar em mínimo lido de gráfico agregado por hora. Ele mostrava piso de
# 166MB; na granularidade de 5 min o piso real é 69MB, e houve uma janela de 13 HORAS
# abaixo de 150MB. Foi em 31/08, durante a carga do histórico — e é justamente por isso
# que o limiar não desce mais: os 3 disparos da regra nova são todos daquele episódio,
# que era um incidente de verdade e DEVE alarmar. No regime desde 01/09 ela fica calada.
#
# Curiosidade contra-intuitiva medida junto: 120MB dispararia MAIS (5x), não menos —
# numa excursão profunda a métrica cruza um limiar baixo várias vezes, entrando e saindo,
# enquanto um limiar mais alto cobre o episódio inteiro como um disparo só.
#
# ⚠️ Isto é ANALGÉSICO, não tratamento: o SwapUsage cresce ~19MB/dia sem parar. A
# correção é subir para db.t4g.medium (falta RAM, não CPU — as duas têm 2 vCPU).
# Enquanto não subir, este alarme calado NÃO quer dizer que o problema sumiu.
alarme "crm-v2-rds-memoria" \
  "Memoria livre do RDS abaixo de 150MB por 15 dos ultimos 25 min. Banco de 1,66GB numa t4g.small de 2GB: subir para medium." \
  AWS/RDS FreeableMemory Average LessThanThreshold 157286400 5 300 \
  notBreaching 3 "Name=DBInstanceIdentifier,Value=$RDS"

alarme "crm-v2-rds-cpu" \
  "CPU do RDS acima de 80% por 10 minutos. Suspeitar de query sem indice." \
  AWS/RDS CPUUtilization Average GreaterThanThreshold 80 2 300 \
  notBreaching - "Name=DBInstanceIdentifier,Value=$RDS"

echo "==> Alarmes do Redis"

# Evictions > 0 significa que o Redis esta descartando chave por falta de memoria.
# Com volatile-lru so cai cache (nao sessao/fila), mas ja indica que a instancia apertou.
alarme "crm-v2-redis-evictions" \
  "Redis descartando chaves por memoria. Subir a instancia; NUNCA trocar para allkeys-lru." \
  AWS/ElastiCache Evictions Sum GreaterThanThreshold 0 1 300 \
  notBreaching - "Name=CacheClusterId,Value=$REDIS"

alarme "crm-v2-redis-memoria" \
  "Uso de memoria do Redis acima de 80%." \
  AWS/ElastiCache DatabaseMemoryUsagePercentage Average GreaterThanThreshold 80 2 300 \
  notBreaching - "Name=CacheClusterId,Value=$REDIS"

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
