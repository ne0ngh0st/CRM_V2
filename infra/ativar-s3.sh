#!/usr/bin/env bash
#
# Aponta uploads e exports para o S3 nos dois app servers, e PROVA que funcionou.
#
# Pré-requisitos:
#   1. IAM role associada às instâncias  → infra/iam/criar-role-s3.sh (precisa de admin)
#   2. league/flysystem-aws-s3-v3 instalado → já está no composer.json desde 7ef92f4
#
#   bash infra/ativar-s3.sh
#
# É IDEMPOTENTE. Não precisa de credencial de admin — só do acesso SSH.
#
# ⚠️ NOTA DE ESTILO, e não é preciosismo: o bloco remoto vai por heredoc **quoted**
# (<<'REMOTO'), não dentro de aspas duplas do ssh. Com aspas duplas o shell LOCAL expande
# $, `` e $() antes de enviar, e um escape esquecido faz parte do script rodar na sua
# máquina — foi o que aconteceu em 2026-08-28 ("require: command not found" na saída, com
# o comando remoto funcionando assim mesmo, por sorte). Heredoc quoted não expande nada.
# Os parâmetros entram como argumentos posicionais ($1, $2), nunca por interpolação.

set -euo pipefail

CHAVE=~/.ssh/crm-v2
NOS="15.229.96.223 54.94.163.16"
BUCKET=crm-v2-arquivos-890615325644
REGIAO=sa-east-1

for IP in $NOS; do
  echo "======== $IP ========"

  ssh -i "$CHAVE" -o BatchMode=yes ubuntu@"$IP" \
      "bash -s -- '$BUCKET' '$REGIAO'" <<'REMOTO'
set -euo pipefail
BUCKET="$1"
REGIAO="$2"
cd /var/www/crm

echo '--> A instância enxerga a IAM role?'
TOKEN=$(curl -s -X PUT http://169.254.169.254/latest/api/token \
          -H 'X-aws-ec2-metadata-token-ttl-seconds: 60')
ROLE=$(curl -s -H "X-aws-ec2-metadata-token: $TOKEN" \
          http://169.254.169.254/latest/meta-data/iam/security-credentials/)
if [ -z "$ROLE" ]; then
  echo '    ERRO: nenhuma role visível. Rode infra/iam/criar-role-s3.sh primeiro.'
  exit 1
fi
echo "    role: $ROLE"

echo '--> Driver do S3 presente?'
# ⚠️ Checar ANTES de mexer no .env. Sem o league/flysystem-aws-s3-v3 o Laravel aceita
# FILESYSTEM_DISK=s3 sem reclamar e só quebra na primeira gravação — e como o accessor
# User::foto_url roda no layout, isso derruba TODA página, não só o upload.
#
# ⚠️ O `require vendor/autoload.php` não é decoração: `php -r` NÃO registra o autoloader
# do Composer sozinho. Sem ele, class_exists() devolve false mesmo com o pacote instalado,
# e esta guarda bloquearia a ativação para sempre por um motivo inexistente.
if ! php -r 'require "vendor/autoload.php"; exit(class_exists("League\\Flysystem\\AwsS3V3\\AwsS3V3Adapter") ? 0 : 1);'; then
  echo "    ERRO: adapter do S3 ausente. Rode 'composer require league/flysystem-aws-s3-v3' e faça deploy antes."
  exit 1
fi
echo '    ok'

echo '--> Ajustando .env'
# ⚠️ Sem AWS_ACCESS_KEY_ID/SECRET de propósito: a AUSÊNCIA deles é o que faz o SDK buscar
# a credencial da role no metadata service. Se alguém colar chaves aqui, elas ganham
# precedência e a role passa a ser ignorada — em silêncio.
sed -i '/^AWS_ACCESS_KEY_ID=/d; /^AWS_SECRET_ACCESS_KEY=/d' .env
sed -i 's|^FILESYSTEM_DISK=.*|FILESYSTEM_DISK=s3|' .env
sed -i 's|^UPLOADS_DISK=.*|UPLOADS_DISK=s3|' .env
sed -i 's|^EXPORTS_DISK=.*|EXPORTS_DISK=s3|' .env
grep -q '^AWS_BUCKET=' .env || echo "AWS_BUCKET=${BUCKET}" >> .env
grep -q '^AWS_DEFAULT_REGION=' .env || echo "AWS_DEFAULT_REGION=${REGIAO}" >> .env
grep -E '^(FILESYSTEM_DISK|UPLOADS_DISK|EXPORTS_DISK|AWS_BUCKET)=' .env | sed 's/^/    /'

echo '--> Recarregando configuração'
php artisan config:clear > /dev/null
php artisan config:cache > /dev/null
sudo systemctl reload php8.3-fpm
php artisan queue:restart > /dev/null

echo '--> Prova de escrita e leitura nos prefixos REAIS'
# Passa pelo mesmo helper que a aplicação usa (App\Support\Uploads\Disco), não por um
# Storage::disk('s3') direto: assim o teste percorre o caminho REAL, incluindo a
# resolução de config.
#
# ⚠️ E usa os prefixos que a aplicação realmente escreve — `facas/`, `perfis/`, `exports/`.
# A primeira versão testava `uploads/`, que NENHUM ponto do código usa: passava com folga
# enquanto todo upload real teria dado AccessDenied pela política IAM. Um teste que não
# percorre o caminho real não prova nada sobre ele.
#
#   facas/   → CatalogoFacaController::186
#   perfis/  → ProfileController::65
#   exports/ → GerarExportacaoCarteiraJob::64
php artisan tinker --execute='
  $alvos = [
      "facas"   => [App\Support\Uploads\Disco::uploads(), App\Support\Uploads\Disco::nomeUploads()],
      "perfis"  => [App\Support\Uploads\Disco::uploads(), App\Support\Uploads\Disco::nomeUploads()],
      "exports" => [App\Support\Uploads\Disco::exports(), App\Support\Uploads\Disco::nomeExports()],
  ];
  foreach ($alvos as $prefixo => [$disco, $nome]) {
      $chave = $prefixo . "/_teste-" . uniqid() . ".txt";
      try {
          $disco->put($chave, "ok");
          $lido = $disco->get($chave);
          $disco->delete($chave);
      } catch (\Throwable $e) {
          $lido = "erro: " . substr($e->getMessage(), 0, 60);
      }
      echo "    " . str_pad($prefixo . "/", 9) . " disco=" . str_pad($nome, 7) . ($lido === "ok" ? "OK" : "FALHOU " . $lido) . PHP_EOL;
  }
' | tee /tmp/prova-s3.txt

# ⚠️ `artisan tinker` sai com código 0 mesmo quando o código dentro dele lança exceção,
# então `set -e` não pega nada. Sem conferir o TEXTO, o script anuncia sucesso com o S3
# quebrado — foi o que ele fez na primeira execução, em 2026-08-28.
if grep -q 'FALHOU\|Error\|Exception' /tmp/prova-s3.txt || [ "$(grep -c 'OK' /tmp/prova-s3.txt)" -ne 3 ]; then
  echo '    ERRO: a prova de escrita/leitura falhou'
  rm -f /tmp/prova-s3.txt
  exit 1
fi
rm -f /tmp/prova-s3.txt
REMOTO
done

echo ""
echo "=== S3 ATIVO E COMPROVADO NOS DOIS NÓS ==="
