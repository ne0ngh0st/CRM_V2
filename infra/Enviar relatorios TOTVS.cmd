@echo off
REM ---------------------------------------------------------------------------
REM  Duplo clique aqui envia os relatorios do TOTVS para o S3 do CRM-V2.
REM
REM  E so uma casca em volta de infra\enviar-relatorios-totvs.ps1 -- a logica
REM  (filtros, espera o arquivo assentar, log) mora la, para nao existir em duas
REM  copias. Este arquivo so existe para dar um alvo clicavel.
REM
REM  Da para criar um atalho na Area de Trabalho apontando para ca.
REM ---------------------------------------------------------------------------
title Enviar relatorios TOTVS - CRM V2
cd /d "%~dp0"

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0enviar-relatorios-totvs.ps1"
set CODIGO=%ERRORLEVEL%

echo.
if %CODIGO% NEQ 0 (
    echo *** DEU ERRO. O log completo esta em:
    echo     %LOCALAPPDATA%\CRM_V2\upload-totvs.log
) else (
    echo Pronto. A producao importa sozinha na virada da hora,
    echo ou na hora pelo botao "Atualizar agora" em:
    echo     https://crm.autopel.online/atualizacoes
)

echo.
echo Pressione qualquer tecla para fechar...
pause >nul
