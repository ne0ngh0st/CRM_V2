# Instala, em modo silencioso, o que a EC2 do gateway do Power BI precisa:
#   1. MySQL Connector/NET (o conector MySQL do Power BI depende dele NA MÁQUINA DO GATEWAY);
#   2. On-premises data gateway (modo padrão — o conector MySQL não funciona no modo pessoal).
#
# Roda pela EC2 via SSM (AWS-RunPowerShellScript), sem RDP. Ver docs/power-bi.md §8.
#
# ⚠️ Instalar NÃO registra o gateway. O registro exige login com a conta Microsoft do Tony,
# pelo configurador, por RDP — isso não é automatizável sem um service principal.
#
# Proteções: o MSI da Oracle só é executado se o MD5 bater com o publicado na página de
# download, e o instalador da Microsoft só se a assinatura digital for válida e da Microsoft.
# Versão nova do Connector/NET = trocar VERSAO_CONNECTOR e MD5_CONNECTOR juntos.

$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$ProgressPreference = 'SilentlyContinue'   # a barra de progresso deixa o download 10x mais lento

$VERSAO_CONNECTOR = '26.7.0'
$MD5_CONNECTOR    = 'ac9249c8957115da437366a55ffab7c1'   # dev.mysql.com/downloads/connector/net, 2026-09-17
$URL_GATEWAY      = 'https://go.microsoft.com/fwlink/?LinkId=2116849&clcid=0x409'

$dir = 'C:\instaladores'
New-Item -ItemType Directory -Force $dir | Out-Null

# --- 1. MySQL Connector/NET -------------------------------------------------------------
$msi = Join-Path $dir "mysql-connector-net-$VERSAO_CONNECTOR.msi"
if (-not (Test-Path $msi)) {
    Invoke-WebRequest "https://cdn.mysql.com/Downloads/Connector-Net/mysql-connector-net-$VERSAO_CONNECTOR.msi" -OutFile $msi -UseBasicParsing
}
$md5 = (Get-FileHash $msi -Algorithm MD5).Hash.ToLower()
if ($md5 -ne $MD5_CONNECTOR) {
    Remove-Item $msi -Force
    throw "MD5 do Connector/NET inesperado ($md5) - instalacao abortada"
}
"Connector/NET $VERSAO_CONNECTOR baixado, MD5 confere."

$p = Start-Process msiexec.exe -Wait -PassThru -ArgumentList @(
    '/i', "`"$msi`"", '/qn', '/norestart', '/l*v', "`"$dir\connector-net.log`""
)
"Connector/NET: codigo de saida $($p.ExitCode) (0 = ok, 1638 = ja instalado)"
if ($p.ExitCode -notin 0, 1638, 3010) { throw "Falha ao instalar o Connector/NET ($($p.ExitCode))" }

# --- 2. On-premises data gateway (modo padrão) --------------------------------------------
$exe = Join-Path $dir 'GatewayInstall.exe'
Invoke-WebRequest $URL_GATEWAY -OutFile $exe -UseBasicParsing
$assinatura = Get-AuthenticodeSignature $exe
"Instalador do gateway: assinatura $($assinatura.Status), $($assinatura.SignerCertificate.Subject)"
if ($assinatura.Status -ne 'Valid' -or $assinatura.SignerCertificate.Subject -notmatch 'O=Microsoft Corporation') {
    throw 'Assinatura do instalador do gateway invalida - instalacao abortada'
}

$p = Start-Process $exe -Wait -PassThru -ArgumentList @(
    '/quiet', '/norestart', 'ACCEPTEULA=yes', '/log', "`"$dir\gateway-install.log`""
)
"Gateway: codigo de saida $($p.ExitCode) (0 = ok, 3010 = pede reinicio)"
if ($p.ExitCode -notin 0, 3010) { throw "Falha ao instalar o gateway ($($p.ExitCode))" }

# --- 3. Conferência ----------------------------------------------------------------------
"--- Servico do gateway"
Get-Service PBIEgwService | Format-List Name, DisplayName, Status, StartType

"--- Provedor MySQL registrado no .NET (teste indicado pela Microsoft)"
$provedor = [System.Data.Common.DbProviderFactories]::GetFactoryClasses() |
    Where-Object { $_.InvariantName -like '*MySql*' }
if ($provedor) { $provedor | Format-List Name, InvariantName } else { 'NAO ENCONTRADO' }
