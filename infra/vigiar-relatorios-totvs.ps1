<#
    Fica em segundo plano chamando o envio dos relatorios de tempos em tempos.

    ---------------------------------------------------------------------------
    POR QUE ISTO EXISTE, EM VEZ DE UMA TAREFA AGENDADA

    Na maquina do Tony (dominio AUTOPEL, conta sem administrador) o Agendador de
    Tarefas aceita registrar a tarefa, dispara no horario, cria o processo, grava no
    log de eventos "concluida com sucesso, codigo de retorno 0" -- e A ACAO NAO
    EXECUTA. Verificado em 2026-09-05 ate o caso mais simples possivel: uma tarefa
    rodando `C:\Windows\System32\cmd.exe /c echo ok > arquivo.txt` no proprio perfil
    do usuario nao cria o arquivo, e ainda assim reporta 0.

    ⚠️ E POR ISSO QUE ESTE ARQUIVO EXISTE, e o alerta vale para qualquer automacao
    futura nesta maquina: `LastTaskResult = 0` do Agendador NAO e prova de que
    alguma coisa aconteceu. A prova e o efeito colateral -- neste caso, o log
    crescer. Se a tarefa agendada tivesse sido dada como pronta por causa do
    codigo 0, o upload teria simplesmente parado de acontecer, em silencio, e o
    sintoma apareceria semanas depois como "o CRM esta com dado velho".

    Um processo comum, lancado pela pasta de Inicializacao do Windows, roda
    normalmente -- foi testado e funciona.
    ---------------------------------------------------------------------------

    Uso (normalmente quem chama e o atalho da Inicializacao):
        powershell -NoProfile -ExecutionPolicy Bypass -File "infra\vigiar-relatorios-totvs.ps1"
        ... -Minutos 10
#>

[CmdletBinding()]
param([int]$Minutos = 5)

$ErrorActionPreference = 'Continue'
$envio = Join-Path $PSScriptRoot 'enviar-relatorios-totvs.ps1'
$log = Join-Path $env:LOCALAPPDATA 'CRM_V2\upload-totvs.log'

if (-not (Test-Path $envio)) { exit 1 }

<#
    INSTANCIA UNICA. Sem isto, cada logon deixaria mais um vigia rodando (e um
    duplo clique no atalho, outro), todos disparando `aws s3 sync` sobre os mesmos
    arquivos ao mesmo tempo. O mutex e global para a sessao do usuario e some
    sozinho quando o processo morre -- nao ha arquivo de lock para ficar orfao.
#>
$mutex = New-Object System.Threading.Mutex($false, 'Global\CRM_V2_VigiaRelatoriosTotvs')
if (-not $mutex.WaitOne(0)) { exit 0 }

try {
    while ($true) {
        # Processo filho de proposito: uma falha do envio (rede caida, credencial
        # expirada) nao pode derrubar o vigia -- ele so tenta de novo no ciclo
        # seguinte. O proprio script de envio ja registra o erro no log.
        Start-Process powershell.exe -WindowStyle Hidden -Wait -ArgumentList @(
            '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', "`"$envio`""
        )

        Start-Sleep -Seconds ($Minutos * 60)
    }
} finally {
    $mutex.ReleaseMutex()
    $mutex.Dispose()
}
