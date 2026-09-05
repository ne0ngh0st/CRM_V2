<#
    Envia os relatorios do TOTVS para o S3 do CRM-V2 -- versao PowerShell, feita para
    rodar SEM terminal: por duplo clique (infra/Enviar relatorios TOTVS.cmd) ou por
    tarefa agendada do Windows (infra/instalar-tarefa-upload.ps1).

    Faz o mesmo que infra/enviar-relatorios-totvs.sh, com dois acrescimos que so fazem
    sentido quando ninguem esta olhando a tela:

      1. ESPERA O ARQUIVO ASSENTAR (ver $MinutosParaAssentar abaixo);
      2. GRAVA LOG, porque numa tarefa agendada a saida nao vai para lugar nenhum.

    Uso:
        powershell -ExecutionPolicy Bypass -File "infra\enviar-relatorios-totvs.ps1"
        ... -DryRun          # mostra o que enviaria, sem enviar

    ---------------------------------------------------------------------------
    POR QUE ISTO NAO PODE SER UM BOTAO NO CRM

    Os relatorios vivem no OneDrive desta maquina e o CRM roda na AWS. Nenhum
    servidor alcanca esta pasta -- por isso a ponte e o S3, e por isso o upload
    precisa partir daqui. O botao "Atualizar agora" da tela /atualizacoes cuida da
    outra metade (S3 -> RDS), que essa sim mora no servidor.
    ---------------------------------------------------------------------------
#>

[CmdletBinding()]
param(
    [switch]$DryRun,
    [string]$Origem = "$env:USERPROFILE\OneDrive - autopel.com\RELATORIOS TOTVS",
    [string]$Bucket = 's3://crm-v2-arquivos-890615325644/totvs',
    [string]$Perfil = 'crm-v2',

    <#
        ATENCAO: NAO BAIXAR ISTO PARA ZERO.

        Um relatorio grande do TOTVS leva minutos sendo escrito no disco. Se o upload
        pegar o arquivo pela metade, o S3 fica com um CSV truncado -- e o importador
        do outro lado nao tem como saber: ele le as linhas que existem e grava um mes
        incompleto, sem erro nenhum. Depois disso a impressao digital ja bateu, entao
        a rodada seguinte nem tenta de novo.

        Este risco nao existe quando alguem roda o script na mao (so roda depois de
        gerar). Ele nasce justamente da automacao, e e o motivo de esta versao existir
        separada da .sh.
    #>
    [int]$MinutosParaAssentar = 3
)

$ErrorActionPreference = 'Stop'
$env:AWS_PROFILE = $Perfil

$LogDir = Join-Path $env:LOCALAPPDATA 'CRM_V2'
$LogFile = Join-Path $LogDir 'upload-totvs.log'
if (-not (Test-Path $LogDir)) { New-Item -ItemType Directory -Path $LogDir -Force | Out-Null }

function Escrever([string]$msg) {
    $linha = "{0}  {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $msg
    Write-Host $linha
    Add-Content -Path $LogFile -Value $linha -Encoding utf8
}

# O log e o unico rastro quando isto roda por tarefa agendada; sem teto ele cresce
# para sempre, igual as tabelas do legado que nunca tinham expurgo.
if ((Test-Path $LogFile) -and ((Get-Item $LogFile).Length -gt 2MB)) {
    Move-Item $LogFile "$LogFile.old" -Force
}

Escrever "=== upload dos relatorios TOTVS ==="

if (-not (Test-Path $Origem)) {
    Escrever "ERRO: pasta de origem nao encontrada: $Origem"
    exit 1
}

if (-not (Get-Command aws -ErrorAction SilentlyContinue)) {
    Escrever 'ERRO: o AWS CLI (aws) nao esta no PATH.'
    exit 1
}

# Mesmos filtros da versao .sh -- ver os comentarios de la sobre por que nao e um
# sync da pasta inteira, por que a estrutura de pastas tem que sobreviver, e por que
# "META VENDA" ficou de fora.
$Filtros = @(
    '--exclude', '*',
    '--include', 'CSV/*.csv',
    '--include', 'Pedidos emitidos/*.csv',
    '--exclude', 'CSV/etc/*',
    '--exclude', 'CSV/META VENDA*'
)

# Arquivos ainda sendo escritos entram como --exclude, para o sync deste ciclo
# ignora-los. Na proxima passada eles ja assentaram e sobem normalmente.
$corte = (Get-Date).AddMinutes(-$MinutosParaAssentar)
$aguardando = @()

foreach ($sub in @('CSV', 'Pedidos emitidos')) {
    $pasta = Join-Path $Origem $sub
    if (-not (Test-Path $pasta)) { continue }

    foreach ($f in Get-ChildItem $pasta -Filter *.csv -File -ErrorAction SilentlyContinue) {
        if ($f.LastWriteTime -gt $corte) {
            $rel = "$sub/$($f.Name)"
            $aguardando += $rel
            $Filtros += @('--exclude', $rel)
            Escrever ("  aguardando assentar ({0:N0}s): {1}" -f ((Get-Date) - $f.LastWriteTime).TotalSeconds, $rel)
        }
    }
}

$argumentos = @('s3', 'sync', $Origem, $Bucket) + $Filtros + @('--delete')
if ($DryRun) { $argumentos += '--dryrun' }

Escrever "origem : $Origem"
Escrever "destino: $Bucket"

$saida = & aws @argumentos 2>&1
$codigo = $LASTEXITCODE

$enviados = @($saida | Where-Object { $_ -match 'upload:' })
foreach ($l in $saida) { Escrever "  $l" }

if ($codigo -ne 0) {
    Escrever "FALHOU (codigo $codigo). Log: $LogFile"
    exit $codigo
}

if ($enviados.Count -eq 0) {
    $extra = if ($aguardando.Count) { " ($($aguardando.Count) ainda assentando)" } else { '' }
    Escrever "nada novo para enviar$extra"
} else {
    Escrever "enviados: $($enviados.Count) arquivo(s)"
    Escrever 'A producao importa sozinha na virada da hora, ou pelo botao em /atualizacoes.'
}

Escrever ''
exit 0
