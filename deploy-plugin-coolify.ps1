#Requires -Version 5.1
# Deploy plugin adn-productos -> El Loro (Coolify)
# Uso completo : .\deploy-plugin-coolify.ps1
# Solo un arch.: .\deploy-plugin-coolify.ps1 -Solo "assets/js/adn-productos.js"
param(
    [string]$Solo = ''   # Ruta relativa del archivo a subir (opcional)
)

# CONFIGURACION
$SshTarget  = 'adn@152.53.55.6'
$Container  = 'wordpress-fywhhaufr2a037pljdg17lmb'
$LocalBase  = 'C:\Users\SoporteAdn\Desktop\delloro\adn-productos'
$PluginDir  = '/var/www/html/wp-content/plugins/adn-productos'
$RemoteDir  = '/var/tmp/adn_deploy_loro'

$LogDir  = Join-Path $PSScriptRoot 'logs'
if (!(Test-Path $LogDir)) { New-Item -ItemType Directory -Path $LogDir -Force | Out-Null }
$LogFile = Join-Path $LogDir ('deploy_plugin_' + (Get-Date -Format 'yyyy-MM-dd_HH-mm-ss') + '.log')

function Write-Log($msg) {
    Write-Host $msg
    Add-Content -Path $LogFile -Value $msg -Encoding UTF8
}

Write-Log "=== Deploy adn-productos -> El Loro ==="
Write-Log "Fecha     : $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')"
if ($Solo) { Write-Log "Modo      : ARCHIVO UNICO -> $Solo" }
Write-Log "Contenedor: $Container"
Write-Log "----------------------------------------"

if ($Solo) {
    # ── MODO RAPIDO: subir solo un archivo ──────────────────────────────────
    $localFile  = Join-Path $LocalBase ($Solo -replace '/', '\')
    $remoteTmp  = "/var/tmp/adn_solo_$(Split-Path $Solo -Leaf)"
    $containerDst = "$PluginDir/$($Solo -replace '\\','/')"

    if (!(Test-Path $localFile)) {
        Write-Log "ERROR: No encontrado: $localFile"; exit 1
    }

    Write-Log ""
    Write-Log "[1/2] scp $Solo (escribe la contrasena)..."
    scp $localFile "${SshTarget}:${remoteTmp}"
    if ($LASTEXITCODE -ne 0) { Write-Log "ERROR: scp fallo."; exit 1 }

    Write-Log ""
    Write-Log "[2/2] docker cp al contenedor (escribe la contrasena)..."
    $cmd = "docker cp $remoteTmp ${Container}:${containerDst} && rm -f $remoteTmp && echo DEPLOY_OK"
    $out = (ssh $SshTarget $cmd 2>&1) | ForEach-Object { "$_" }
    Write-Log ($out -join "`n")
    if ($LASTEXITCODE -ne 0 -or ($out -notmatch "DEPLOY_OK")) {
        Write-Log "ERROR: Fallo."; exit 1
    }

} else {
    # ── MODO COMPLETO: subir todos los archivos ──────────────────────────────
    if (!(Test-Path $LocalBase)) { Write-Log "ERROR: No se encontro: $LocalBase"; exit 1 }

    Write-Log ""
    Write-Log "[1/2] Copiando plugin al servidor (escribe la contrasena)..."
    scp -r $LocalBase "${SshTarget}:${RemoteDir}"
    if ($LASTEXITCODE -ne 0) { Write-Log "ERROR: scp fallo."; exit 1 }
    Write-Log "OK: Archivos copiados a $RemoteDir"

    Write-Log ""
    Write-Log "[2/2] Instalando en contenedor (escribe la contrasena)..."
    $cmd = "set -e; " +
           "docker exec $Container mkdir -p $PluginDir/assets/css $PluginDir/assets/js; " +
           "docker cp $RemoteDir/adn-productos.php ${Container}:${PluginDir}/adn-productos.php; " +
           "docker cp $RemoteDir/assets/css/style.css ${Container}:${PluginDir}/assets/css/style.css; " +
           "docker cp $RemoteDir/assets/js/adn-productos.js ${Container}:${PluginDir}/assets/js/adn-productos.js; " +
           "docker cp $RemoteDir/assets/js/adn-slider.js ${Container}:${PluginDir}/assets/js/adn-slider.js; " +
           "rm -rf $RemoteDir; echo DEPLOY_OK"
    $output   = (ssh $SshTarget $cmd 2>&1) | ForEach-Object { "$_" }
    $exitCode = $LASTEXITCODE
    Write-Log ($output -join "`n")
    if ($exitCode -ne 0 -or ($output -notmatch "DEPLOY_OK")) {
        Write-Log "ERROR: Fallo el deploy (codigo $exitCode)"; exit 1
    }
}

Write-Log ""
Write-Log "LISTO: Plugin desplegado correctamente!"
Write-Log "WP Admin: http://wordpress-fywhhaufr2a037pljdg17lmb.152.53.55.6.sslip.io/wp-admin/"
Write-Log "Log     : $LogFile"
