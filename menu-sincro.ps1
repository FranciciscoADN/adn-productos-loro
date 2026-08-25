<#
.SYNOPSIS
    Menu interactivo para sincronizacion ADN <-> WordPress El Loro.
    Permite revisar logs y ejecutar sincronizaciones manuales.
    Ejecutar en el servidor ADN: powershell -ExecutionPolicy Bypass -File "C:\ADN Software\sincro\menu-sincro.ps1"
#>

$script  = "C:\ADN Software\sincro\sincro-adn-loro.ps1"
$log_dir = "C:\ADN Software\sincro\logs"

# ── Colores y utilidades ──────────────────────────────────────────────────────
function Write-Header {
    Clear-Host
    Write-Host "================================================" -ForegroundColor Cyan
    Write-Host "   ADN <-> El Loro  |  Panel de Sincronizacion  " -ForegroundColor Cyan
    Write-Host "================================================" -ForegroundColor Cyan
    Write-Host ""
}

function Show-TaskStatus {
    Write-Host "  Estado de tareas programadas:" -ForegroundColor Yellow
    $tasks = Get-ScheduledTask | Where-Object { $_.TaskName -like "ADN-Loro-*" } | Sort-Object TaskName
    foreach ($t in $tasks) {
        $info  = Get-ScheduledTaskInfo -TaskName $t.TaskName -ErrorAction SilentlyContinue
        $state = if ($t.State -eq "Ready") { "[OK]  " } else { "[!]   " }
        $color = if ($t.State -eq "Ready") { "Green" } else { "Red" }
        $last  = if ($info.LastRunTime -gt [datetime]"2000-01-01") {
                     $info.LastRunTime.ToString("dd/MM/yyyy HH:mm")
                 } else { "Nunca" }
        $result = if ($info.LastTaskResult -eq 0) { "OK" } else { "Error($($info.LastTaskResult))" }
        Write-Host ("  $state {0,-28} ultimo: {1}  resultado: {2}" -f $t.TaskName, $last, $result) -ForegroundColor $color
    }
    Write-Host ""
}

function Show-LastLog {
    param([string]$Modo)
    $files = Get-ChildItem "$log_dir\${Modo}_*.log" -ErrorAction SilentlyContinue |
             Sort-Object LastWriteTime -Descending
    if (-not $files) {
        Write-Host "  No se encontraron logs para '$Modo'." -ForegroundColor DarkGray
        return
    }
    $latest = $files[0]
    Write-Host ""
    Write-Host "  Log: $($latest.Name)  ($([math]::Round($latest.Length/1KB,1)) KB)" -ForegroundColor Yellow
    Write-Host "  ----------------------------------------" -ForegroundColor DarkGray
    Get-Content $latest.FullName | Select-Object -Last 60 | ForEach-Object {
        $color = if ($_ -match "ERROR|error")  { "Red"     } `
            elseif ($_ -match "WARN|warn")     { "Yellow"  } `
            elseif ($_ -match "OK|exitoso|fin") { "Green"   } `
            else                               { "Gray"    }
        Write-Host "  $_" -ForegroundColor $color
    }
    Write-Host ""
}

function Show-AllLogs {
    Write-Host ""
    Write-Host "  Archivos de log disponibles:" -ForegroundColor Yellow
    Write-Host "  ----------------------------------------" -ForegroundColor DarkGray
    $files = Get-ChildItem "$log_dir\*.log" -ErrorAction SilentlyContinue |
             Sort-Object LastWriteTime -Descending
    if (-not $files) {
        Write-Host "  No hay logs todavia." -ForegroundColor DarkGray
        return
    }
    $i = 1
    foreach ($f in $files | Select-Object -First 30) {
        $age = [math]::Round(((Get-Date) - $f.LastWriteTime).TotalHours, 1)
        Write-Host ("  {0,2}. {1,-45} {2,6} KB   hace {3}h" -f $i, $f.Name, [math]::Round($f.Length/1KB,1), $age) -ForegroundColor Gray
        $i++
    }
    Write-Host ""
    $sel = Read-Host "  Numero de log a ver (Enter para volver)"
    if ($sel -match "^\d+$") {
        $idx = [int]$sel - 1
        if ($idx -ge 0 -and $idx -lt $files.Count) {
            $f = ($files | Select-Object -First 30)[$idx]
            Write-Host ""
            Write-Host "  === $($f.Name) ===" -ForegroundColor Cyan
            Get-Content $f.FullName | ForEach-Object {
                $color = if ($_ -match "ERROR|error")   { "Red"    } `
                    elseif ($_ -match "WARN|warn")      { "Yellow" } `
                    elseif ($_ -match "OK|exitoso|fin") { "Green"  } `
                    else                                { "Gray"   }
                Write-Host "  $_" -ForegroundColor $color
            }
        }
    }
}

function Run-Sync {
    param([string]$Modo, [string]$Label)
    Write-Host ""
    Write-Host "  Ejecutando: $Label ..." -ForegroundColor Cyan
    $ts      = Get-Date -Format "yyyy-MM-dd_HH-mm"
    $logfile = "$log_dir\${Modo}_manual_$ts.log"
    New-Item -ItemType Directory -Force -Path $log_dir | Out-Null
    & $script -Modo $Modo -LogFile $logfile
    Write-Host ""
    Write-Host "  Sincronizacion finalizada." -ForegroundColor Green
    Write-Host "  Log guardado en: $logfile" -ForegroundColor Yellow
}

# ── Menu principal ────────────────────────────────────────────────────────────
do {
    Write-Header
    Show-TaskStatus

    Write-Host "  ---- SINCRONIZACION MANUAL ----" -ForegroundColor White
    Write-Host "   1  Marcas          (ADN -> Web)"
    Write-Host "   2  Categorias      (ADN -> Web)"
    Write-Host "   3  Productos       (ADN -> Web)"
    Write-Host "   4  Clientes        (ADN -> Web)"
    Write-Host "   5  Existencias     (ADN -> Web)"
    Write-Host "   6  Imagenes        (ADN -> Web)"
    Write-Host "   7  Pedidos         (Web -> ADN)"
    Write-Host "   8  TODO el catalogo (1+2+3+4)"
    Write-Host ""
    Write-Host "  ---- REVISAR LOGS ----" -ForegroundColor White
    Write-Host "   L1  Ultimo log de Catalogo"
    Write-Host "   L2  Ultimo log de Existencias"
    Write-Host "   L3  Ultimo log de Imagenes"
    Write-Host "   L4  Ultimo log de Pedidos"
    Write-Host "   LA  Ver todos los logs"
    Write-Host ""
    Write-Host "   0  Salir" -ForegroundColor DarkGray
    Write-Host ""

    $op = (Read-Host "  Opcion").Trim().ToUpper()

    switch ($op) {
        "1"  { Run-Sync "marcas"      "Marcas ADN -> Web" }
        "2"  { Run-Sync "categorias"  "Categorias ADN -> Web" }
        "3"  { Run-Sync "productos"   "Productos ADN -> Web" }
        "4"  { Run-Sync "clientes"    "Clientes ADN -> Web" }
        "5"  { Run-Sync "existencias" "Existencias (stock) ADN -> Web" }
        "6"  { Run-Sync "imagenes"    "Imagenes ADN -> Web" }
        "7"  { Run-Sync "pedidos"     "Pedidos Web -> ADN" }
        "8"  {
            Run-Sync "marcas"     "Marcas"
            Run-Sync "categorias" "Categorias"
            Run-Sync "productos"  "Productos"
            Run-Sync "clientes"   "Clientes"
        }
        "L1" { Show-LastLog "catalogo" }
        "L2" { Show-LastLog "existencias" }
        "L3" { Show-LastLog "imagenes" }
        "L4" { Show-LastLog "pedidos" }
        "LA" { Show-AllLogs }
        "0"  { break }
        default { Write-Host "  Opcion invalida." -ForegroundColor Red }
    }

    if ($op -ne "0") {
        Write-Host ""
        Read-Host "  Presiona Enter para volver al menu"
    }

} while ($op -ne "0")

Write-Host ""
Write-Host "  Hasta luego." -ForegroundColor Cyan
