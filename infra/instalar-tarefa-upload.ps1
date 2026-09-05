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

<#
    DOIS GATILHOS, e o primeiro nao e redundante.

    ATENCAO: so com -AtLogOn a tarefa nao roda ao ser instalada -- o gatilho ja
    passou, porque quem instala esta logado ha horas. Ela ficaria parada ate o
    proximo logon, e a pessoa que acabou de instalar concluiria que "nao funciona"
    (ou pior: acharia que esta rodando e nao esta). O gatilho -Once comecando AGORA,
    com repeticao indefinida, e o que faz valer no minuto seguinte a instalacao.

    O -AtLogOn continua porque e ele que garante o rearme depois de reiniciar o PC,
    com 2 min de atraso para nao disputar CPU com o resto que sobe junto com a
    sessao.
#>
# ATENCAO: -RepetitionDuration OMITIDO de proposito. No XML do Agendador, Duration
# vazia significa "repetir indefinidamente", que e o que queremos. A receita comum
# de usar [TimeSpan]::MaxValue NAO funciona aqui: vira P99999999DT23H59M59S e o
# Register-ScheduledTask recusa com "valor formatado incorretamente ou fora do
# intervalo" (HRESULT 0x80041318). Foi o erro real da primeira instalacao.
$agora = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) `
    -RepetitionInterval (New-TimeSpan -Minutes $Minutos)

# ATENCAO: -User e OBRIGATORIO aqui. `New-ScheduledTaskTrigger -AtLogOn` sem usuario
# significa "no logon de QUALQUER usuario" -- e isso e system-wide, entao o
# Register-ScheduledTask devolve "Acesso negado" (0x80070005) numa conta sem
# administrador. Foi o segundo erro real da instalacao, e ele nao aponta para o
# gatilho: parece falta de permissao para criar tarefa, quando criar tarefa
# funciona normalmente. Escopado ao proprio usuario, registra sem admin.
$aoLogar = New-ScheduledTaskTrigger -AtLogOn -User "$env:USERDOMAIN\$env:USERNAME"
$aoLogar.Delay = 'PT2M'
$aoLogar.Repetition = $agora.Repetition

$gatilho = @($agora, $aoLogar)

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

<#
    ATENCAO: A INSTALACAO NAO TERMINA AQUI -- ela e VERIFICADA.

    Na maquina do Tony (dominio AUTOPEL, conta sem administrador) o Agendador
    aceita registrar, dispara no horario, cria o processo e grava no log de eventos
    "concluida com sucesso, codigo de retorno 0" -- SEM EXECUTAR A ACAO. Testado ate
    o caso minimo: cmd.exe do System32 escrevendo um arquivo no proprio perfil do
    usuario nao cria o arquivo, e mesmo assim reporta 0.

    Uma tarefa assim e PIOR que nenhuma: parece instalada, o Windows jura que rodou,
    e o upload simplesmente para de acontecer em silencio -- o sintoma aparece
    semanas depois como "o CRM esta com dado velho". Por isso o teste abaixo nao
    olha codigo de retorno nenhum: olha o EFEITO (o log crescer). Se nao cresceu, a
    tarefa e removida e o script manda usar o duplo clique manual.

    ⚠️ NAO mandar para infra/instalar-inicializacao.ps1: a pasta de Inicializacao
    TAMBEM nao dispara nesta maquina (testado com reboot real em 2026-09-05 -- o
    .vbs fica no caminho certo e o Explorer nao o executa no logon). Aqui nao existe
    automacao de logon que funcione; o que funciona e o duplo clique.
#>
$log = Join-Path $env:LOCALAPPDATA 'CRM_V2\upload-totvs.log'
$antes = if (Test-Path -LiteralPath $log) { (Get-Item -LiteralPath $log).Length } else { 0 }

Write-Host 'Verificando se a acao realmente executa...'
Start-ScheduledTask -TaskName $Nome
$funcionou = $false
foreach ($i in 1..20) {
    Start-Sleep -Seconds 3
    $agora = if (Test-Path -LiteralPath $log) { (Get-Item -LiteralPath $log).Length } else { 0 }
    if ($agora -gt $antes) { $funcionou = $true; break }
}

if (-not $funcionou) {
    Unregister-ScheduledTask -TaskName $Nome -Confirm:$false
    Write-Host ''
    Write-Host 'FALHOU: o Agendador aceitou a tarefa mas a acao nao produziu efeito nenhum'
    Write-Host '(o log nao cresceu). Isto e politica desta maquina, nao erro do script --'
    Write-Host 'o Windows reporta sucesso mesmo assim, entao a tarefa foi REMOVIDA para nao'
    Write-Host 'ficar mentindo que funciona.'
    Write-Host ''
    Write-Host 'Use o duplo clique, que foi testado e funciona:'
    Write-Host '  infra\Enviar relatorios TOTVS.cmd'
    Write-Host ''
    Write-Host 'A pasta de Inicializacao tambem nao dispara nesta maquina -- ver'
    Write-Host 'docs/importacao-dados-legado.md secao 10.7 antes de tentar de novo.'
    exit 1
}

Write-Host "Tarefa '$Nome' instalada e VERIFICADA: a cada $Minutos minuto(s), a partir do logon."
Write-Host "Log: $env:LOCALAPPDATA\CRM_V2\upload-totvs.log"
Write-Host ''
Write-Host 'Para remover:  powershell -ExecutionPolicy Bypass -File "infra\instalar-tarefa-upload.ps1" -Remover'
