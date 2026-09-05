<#
    Registra (ou remove) uma tarefa agendada do Windows que envia os relatorios do
    TOTVS para o S3 sozinha, de tempos em tempos.

    Com isto o fluxo do Tony vira UM passo: gerar o relatorio no TOTVS e salvar na
    pasta. O resto anda sem ninguem tocar em nada -- a tarefa sobe para o S3, e o
    cron da app-2 importa para o RDS na virada da hora.

    Uso (PowerShell normal, NAO precisa de administrador):
        powershell -ExecutionPolicy Bypass -File "infra\instalar-tarefa-upload.ps1"
        ... -Minutos 10        # intervalo (padrao 5)
        ... -Remover           # desinstala

    ---------------------------------------------------------------------------
    NAO PRECISA DE ADMINISTRADOR, e isso e proposital: a tarefa roda como o
    proprio usuario, que e quem tem o perfil do AWS CLI ('crm-v2') e quem enxerga
    a pasta do OneDrive. Registrada como SYSTEM ela nao teria nem a credencial nem
    a pasta -- falharia toda vez, em silencio.
    ---------------------------------------------------------------------------
#>

[CmdletBinding()]
param(
    [int]$Minutos = 5,
    [switch]$Remover
)

$ErrorActionPreference = 'Stop'
$Nome = 'CRM V2 - Enviar relatorios TOTVS'
$Script = Join-Path $PSScriptRoot 'enviar-relatorios-totvs.ps1'

if ($Remover) {
    if (Get-ScheduledTask -TaskName $Nome -ErrorAction SilentlyContinue) {
        Unregister-ScheduledTask -TaskName $Nome -Confirm:$false
        Write-Host "Tarefa '$Nome' removida."
    } else {
        Write-Host "Tarefa '$Nome' nao estava instalada."
    }
    return
}

if (-not (Test-Path $Script)) { throw "Nao encontrei $Script" }

# -WindowStyle Hidden para nao piscar console na cara do usuario a cada 5 minutos.
$acao = New-ScheduledTaskAction -Execute 'powershell.exe' `
    -Argument ('-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File "{0}"' -f $Script)

# Repeticao sem fim a partir do logon. Comeca 2 min depois para nao disputar CPU com
# o resto que sobe junto com a sessao.
$gatilho = New-ScheduledTaskTrigger -AtLogOn
$gatilho.Delay = 'PT2M'
$gatilho.Repetition = (New-ScheduledTaskTrigger -Once -At (Get-Date) `
    -RepetitionInterval (New-TimeSpan -Minutes $Minutos) `
    -RepetitionDuration ([TimeSpan]::MaxValue)).Repetition

<#
    StartWhenAvailable: se a maquina estava desligada na hora, roda assim que ligar.
    DontStopOnIdleEnd / -RunOnlyIfIdle ausente: nao queremos que pare porque o Tony
      voltou a mexer no computador.
    ExecutionTimeLimit 1h: upload travado nao pode ficar preso para sempre bloqueando
      as proximas execucoes.
    MultipleInstances IgnoreNew: se a rodada anterior ainda esta subindo 200 MB, a
      seguinte e descartada em vez de empilhar dois syncs sobre os mesmos arquivos.
#>
$config = New-ScheduledTaskSettingsSet `
    -StartWhenAvailable `
    -DontStopOnIdleEnd `
    -ExecutionTimeLimit (New-TimeSpan -Hours 1) `
    -MultipleInstances IgnoreNew `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries

Register-ScheduledTask -TaskName $Nome -Action $acao -Trigger $gatilho -Settings $config `
    -Description 'Envia os relatorios do TOTVS (RELATORIOS TOTVS) para o S3 do CRM-V2. Ver infra/enviar-relatorios-totvs.ps1.' `
    -Force | Out-Null

Write-Host "Tarefa '$Nome' instalada: a cada $Minutos minuto(s), a partir do logon."
Write-Host "Log: $env:LOCALAPPDATA\CRM_V2\upload-totvs.log"
Write-Host ''
Write-Host 'Para remover:  powershell -ExecutionPolicy Bypass -File "infra\instalar-tarefa-upload.ps1" -Remover'
