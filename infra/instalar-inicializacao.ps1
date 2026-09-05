<#
    Faz o envio dos relatorios rodar sozinho, colocando um lancador na pasta de
    Inicializacao do Windows (o vigia sobe junto com a sessao e repete de N em N
    minutos).

    ⚠️ NAO USE ISTO NA MAQUINA DO TONY -- NAO FUNCIONA LA. Testado em 2026-09-05 com
    um reboot de verdade: o .vbs e instalado no caminho certo (sem redirecionamento de
    pasta, sem estar desabilitado em StartupApproved) e o Explorer simplesmente NAO o
    executa no logon -- 5,5 min depois do boot, nenhum processo e nenhuma linha no log.
    Chamado a mao, funciona. Suspeita nao confirmada: .vbs na Inicializacao e a tecnica
    classica de persistencia de malware, e EDR corporativo costuma bloquear isso no
    logon permitindo a execucao manual.

    A tarefa agendada (infra/instalar-tarefa-upload.ps1) tambem nao funciona la, por
    outro motivo: reporta "codigo de retorno 0" sem executar a acao.

    O que se usa hoje e o duplo clique em "Enviar relatorios TOTVS.cmd". Este script
    continua aqui porque funciona em maquina sem essa politica -- e porque
    `vigiar-relatorios-totvs.ps1`, que ele instala, funciona normalmente quando lancado
    a mao. Ver docs/importacao-dados-legado.md secao 10.7.

    Uso (PowerShell normal, sem administrador):
        powershell -ExecutionPolicy Bypass -File "infra\instalar-inicializacao.ps1"
        ... -Minutos 10        # intervalo (padrao 5)
        ... -Remover           # desinstala
        ... -NaoIniciarAgora   # so instala, sem subir o vigia nesta sessao
#>

[CmdletBinding()]
param(
    [int]$Minutos = 5,
    [switch]$Remover,
    [switch]$NaoIniciarAgora
)

$ErrorActionPreference = 'Stop'

$Startup = [Environment]::GetFolderPath('Startup')
$Lancador = Join-Path $Startup 'CRM V2 - Enviar relatorios TOTVS.vbs'
$Vigia = Join-Path $PSScriptRoot 'vigiar-relatorios-totvs.ps1'

if ($Remover) {
    if (Test-Path -LiteralPath $Lancador) {
        Remove-Item -LiteralPath $Lancador -Force
        Write-Host "Lancador removido de: $Startup"
    } else {
        Write-Host 'Nao estava instalado.'
    }
    Write-Host 'O vigia que ja estiver rodando continua ate voce reiniciar/deslogar.'
    return
}

if (-not (Test-Path $Vigia)) { throw "Nao encontrei $Vigia" }

<#
    .vbs e nao .lnk/.cmd de proposito: e a unica forma de subir sem PISCAR um
    console preto na cara do usuario a cada logon. `WScript.Shell.Run` com estilo 0
    esconde de verdade; `-WindowStyle Hidden` sozinho ainda mostra a janela por uma
    fracao de segundo.

    ⚠️ O caminho do repositorio esta escrito aqui dentro. Se a pasta do projeto
    mudar de lugar, rode este instalador de novo -- o lancador antigo apontaria para
    um caminho que nao existe mais e falharia em silencio, que e exatamente o modo
    de falha que este projeto ja viu demais.
#>
$comando = 'powershell.exe -NoProfile -ExecutionPolicy Bypass -File ""{0}"" -Minutos {1}' -f $Vigia, $Minutos
$conteudo = @"
' Sobe o vigia de envio dos relatorios do TOTVS para o S3 do CRM-V2, sem janela.
' Gerado por infra\instalar-inicializacao.ps1 -- nao editar a mao.
Set sh = CreateObject("WScript.Shell")
sh.Run "$comando", 0, False
"@

Set-Content -LiteralPath $Lancador -Value $conteudo -Encoding ascii
Write-Host "Lancador instalado em: $Lancador"
Write-Host "Intervalo: $Minutos minuto(s)."

if (-not $NaoIniciarAgora) {
    # A pasta de Inicializacao so age no proximo logon; sem isto o usuario instalaria
    # e ficaria sem nada rodando ate reiniciar -- o mesmo tipo de armadilha do
    # gatilho -AtLogOn da tarefa agendada.
    & wscript.exe $Lancador
    Write-Host 'Vigia iniciado agora (nao precisa reiniciar).'
}

Write-Host ''
Write-Host "Log: $env:LOCALAPPDATA\CRM_V2\upload-totvs.log"
Write-Host 'Para remover:  powershell -ExecutionPolicy Bypass -File "infra\instalar-inicializacao.ps1" -Remover'
