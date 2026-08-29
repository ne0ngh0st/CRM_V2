#!/usr/bin/env bash
#
# Alarmes sobre as métricas que a APLICAÇÃO publica (namespace CRM-V2).
#
#   AWS_PROFILE=crm-v2 bash infra/monitoramento/criar-alarmes-aplicacao.sh
#
# Pré-requisito: `metricas:publicar` rodando pelo scheduler (routes/console.php).
# É IDEMPOTENTE.
#
# Estes são os alarmes que teriam pegado o incidente de 2026-08-29. Os nove alarmes
# nativos do outro script ficaram VERDES durante as seis horas em que a fila esteve
# travada — CPU em 0,4%, ALB saudável, zero 5xx. A AWS não tem como enxergar fila.
#
# ⚠️ `treat-missing-data breaching` nos dois, ao contrário dos alarmes nativos.
# A razão é a diferença entre "não houve erro" e "ninguém está reportando":
#   - lá, dado ausente significa que nada de ruim aconteceu (não houve 5xx);
#   - aqui, dado ausente significa que o scheduler parou de publicar — ou seja, o
#     próprio mecanismo de detecção morreu. Silêncio não pode ser lido como saúde.

set -euo pipefail
export MSYS_NO_PATHCONV=1

REGIAO=sa-east-1
ARN_TOPICO=arn:aws:sns:sa-east-1:890615325644:crm-v2-alertas

echo "==> Alarme: fila travada"
# 50 jobs por 3 minutos seguidos. No incidente a fila passou de 2.700 e ficou horas
# assim; em operação normal ela drena em segundos e fica em zero. O limiar é folgado
# para não disparar num pico legítimo (uma exportação grande enfileira vários jobs).
aws cloudwatch put-metric-alarm --region "$REGIAO" \
  --alarm-name "crm-v2-fila-travada" \
  --alarm-description "Fila acima de 50 jobs por 3 minutos. Ver: queue:failed e /var/log/crm-worker.log no app-2. Causa conhecida: broadcast do Reverb sem rota (SG 8080)." \
  --namespace CRM-V2 --metric-name FilaProfundidade --statistic Maximum \
  --comparison-operator GreaterThanThreshold --threshold 50 \
  --evaluation-periods 3 --period 60 \
  --treat-missing-data breaching \
  --alarm-actions "$ARN_TOPICO" --ok-actions "$ARN_TOPICO"
echo "    crm-v2-fila-travada"

echo "==> Alarme: cache esfriando"
# O warming roda a cada 10 min contra um TTL de 30. Passar de 25 minutos sem aquecer
# significa que o job não está completando — worker morto, scheduler desligado ou fila
# entupida. Pega os três pelo mesmo sintoma, que é o que o usuário sente.
aws cloudwatch put-metric-alarm --region "$REGIAO" \
  --alarm-name "crm-v2-cache-esfriando" \
  --alarm-description "Sem aquecimento ha mais de 25 minutos. O TTL e 30: passando disso as paginas voltam a ~5s. Checar worker, scheduler e fila." \
  --namespace CRM-V2 --metric-name AquecimentoIdadeMinutos --statistic Maximum \
  --comparison-operator GreaterThanThreshold --threshold 25 \
  --evaluation-periods 2 --period 60 \
  --treat-missing-data breaching \
  --alarm-actions "$ARN_TOPICO" --ok-actions "$ARN_TOPICO"
echo "    crm-v2-cache-esfriando"

echo "==> Alarme: jobs falhando"
# Falha isolada acontece; 20 em cinco minutos é padrão, não acidente.
aws cloudwatch put-metric-alarm --region "$REGIAO" \
  --alarm-name "crm-v2-jobs-falhando" \
  --alarm-description "Mais de 20 jobs na failed_jobs. Ver php artisan queue:failed." \
  --namespace CRM-V2 --metric-name JobsFalhados --statistic Maximum \
  --comparison-operator GreaterThanThreshold --threshold 20 \
  --evaluation-periods 1 --period 300 \
  --treat-missing-data notBreaching \
  --alarm-actions "$ARN_TOPICO" --ok-actions "$ARN_TOPICO"
echo "    crm-v2-jobs-falhando"

echo ""
aws cloudwatch describe-alarms --region "$REGIAO" --alarm-name-prefix crm-v2 \
  --query 'MetricAlarms[].{Alarme:AlarmName,Estado:StateValue}' --output table
