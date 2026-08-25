<#
.SYNOPSIS
    Registra las tareas programadas de sincronización ADN <-> WordPress El Loro.
    Ejecutar como Administrador en el servidor ADN.

    TAREAS:
      ADN-Loro-Catalogo    : marcas + categorias + productos + clientes  (cada 2h, 06:00-20:00)
      ADN-Loro-Existencias : stock/existencias                           (cada 1h, 06:00-22:00)
      ADN-Loro-Imagenes    : imagenes de productos                       (diario 23:00)
      ADN-Loro-Pedidos     : pedidos web -> ADN (crea clientes)          (cada 30 min)
      ADN-Loro-Logs-Rotate : limpia logs con mas de 30 dias              (diario 00:30)
#>

$ps_exe   = "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe"
$script   = "C:\ADN Software\sincro\sincro-adn-loro.ps1"
$log_dir  = "C:\ADN Software\sincro\logs"
$work_dir = "C:\ADN Software\sincro"

# Crear directorio de logs si no existe
New-Item -ItemType Directory -Force -Path $log_dir | Out-Null
Write-Host "Directorio de logs: $log_dir" -ForegroundColor Cyan

# ─── Funcion auxiliar para construir argumento con log fechado ────────────────
# Cada ejecucion crea: logs\<modo>_YYYY-MM-DD_HH-mm.log
function Build-Arg {
    param([string]$Modo, [string]$ExtraModosCmd = "")

    # Log con fecha+hora para identificar cada ejecucion
    $log_path = "$log_dir\$Modo`_`$(Get-Date -Format 'yyyy-MM-dd_HH-mm').log"

    if ($ExtraModosCmd) {
        # Varios modos en secuencia (catalogo)
        return "-ExecutionPolicy Bypass -NonInteractive -WindowStyle Hidden -Command " +
               "& { `$log = '$log_dir\catalogo_' + (Get-Date -Format 'yyyy-MM-dd_HH-mm') + '.log'; " +
               $ExtraModosCmd + " } *>> `$log"
    } else {
        return "-ExecutionPolicy Bypass -NonInteractive -WindowStyle Hidden " +
               "-File `"$script`" -Modo $Modo " +
               "-LogFile `"$log_dir\$Modo`_`$(Get-Date -Format 'yyyy-MM-dd_HH-mm').log`""
    }
}

# ─────────────────────────────────────────────────────────────────────────────
# TAREA 1: Catalogo ADN -> Web
#   Sincroniza: marcas, categorias, productos, clientes
#   Frecuencia: cada 2 horas de 06:00 a 20:00
# ─────────────────────────────────────────────────────────────────────────────
$cat_cmd = "& `"$script`" -Modo marcas -LogFile `$log; " +
           "& `"$script`" -Modo categorias -LogFile `$log; " +
           "& `"$script`" -Modo productos -LogFile `$log; " +
           "& `"$script`" -Modo clientes -LogFile `$log"

$cat_inner = "`$log = '$log_dir\catalogo_' + (Get-Date -Format 'yyyy-MM-dd_HH-mm') + '.log'; $cat_cmd"

$action_cat = New-ScheduledTaskAction `
    -Execute  $ps_exe `
    -Argument "-ExecutionPolicy Bypass -NonInteractive -WindowStyle Hidden -Command `"& { $cat_inner }`"" `
    -WorkingDirectory $work_dir

$trigger_cat = @(
    (New-ScheduledTaskTrigger -Daily -At "06:00"),
    (New-ScheduledTaskTrigger -Daily -At "08:00"),
    (New-ScheduledTaskTrigger -Daily -At "10:00"),
    (New-ScheduledTaskTrigger -Daily -At "12:00"),
    (New-ScheduledTaskTrigger -Daily -At "14:00"),
    (New-ScheduledTaskTrigger -Daily -At "16:00"),
    (New-ScheduledTaskTrigger -Daily -At "18:00"),
    (New-ScheduledTaskTrigger -Daily -At "20:00")
)

$settings_cat = New-ScheduledTaskSettingsSet `
    -ExecutionTimeLimit (New-TimeSpan -Hours 2) `
    -MultipleInstances IgnoreNew `
    -StartWhenAvailable

Register-ScheduledTask `
    -TaskName    "ADN-Loro-Catalogo" `
    -Description "marcas + categorias + productos + clientes  ADN -> Web (cada 2h 06-20)" `
    -Action      $action_cat `
    -Trigger     $trigger_cat `
    -Settings    $settings_cat `
    -RunLevel    Highest `
    -Force | Out-Null

Write-Host "[OK] ADN-Loro-Catalogo      -> cada 2h de 06:00 a 20:00  | logs\catalogo_FECHA.log" -ForegroundColor Green

# ─────────────────────────────────────────────────────────────────────────────
# TAREA 2: Existencias ADN -> Web
#   Sincroniza: solo stock de productos (rapido, frecuente)
#   Frecuencia: cada 1 hora de 06:00 a 22:00
# ─────────────────────────────────────────────────────────────────────────────
$ext_inner = "`$log = '$log_dir\existencias_' + (Get-Date -Format 'yyyy-MM-dd_HH-mm') + '.log'; & `"$script`" -Modo existencias -LogFile `$log"

$action_ext = New-ScheduledTaskAction `
    -Execute  $ps_exe `
    -Argument "-ExecutionPolicy Bypass -NonInteractive -WindowStyle Hidden -Command `"& { $ext_inner }`"" `
    -WorkingDirectory $work_dir

$trigger_ext = @(
    (New-ScheduledTaskTrigger -Daily -At "06:00"),
    (New-ScheduledTaskTrigger -Daily -At "07:00"),
    (New-ScheduledTaskTrigger -Daily -At "08:00"),
    (New-ScheduledTaskTrigger -Daily -At "09:00"),
    (New-ScheduledTaskTrigger -Daily -At "10:00"),
    (New-ScheduledTaskTrigger -Daily -At "11:00"),
    (New-ScheduledTaskTrigger -Daily -At "12:00"),
    (New-ScheduledTaskTrigger -Daily -At "13:00"),
    (New-ScheduledTaskTrigger -Daily -At "14:00"),
    (New-ScheduledTaskTrigger -Daily -At "15:00"),
    (New-ScheduledTaskTrigger -Daily -At "16:00"),
    (New-ScheduledTaskTrigger -Daily -At "17:00"),
    (New-ScheduledTaskTrigger -Daily -At "18:00"),
    (New-ScheduledTaskTrigger -Daily -At "19:00"),
    (New-ScheduledTaskTrigger -Daily -At "20:00"),
    (New-ScheduledTaskTrigger -Daily -At "21:00"),
    (New-ScheduledTaskTrigger -Daily -At "22:00")
)

$settings_ext = New-ScheduledTaskSettingsSet `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 30) `
    -MultipleInstances IgnoreNew `
    -StartWhenAvailable

Register-ScheduledTask `
    -TaskName    "ADN-Loro-Existencias" `
    -Description "Stock/existencias ADN -> Web (cada hora 06-22)" `
    -Action      $action_ext `
    -Trigger     $trigger_ext `
    -Settings    $settings_ext `
    -RunLevel    Highest `
    -Force | Out-Null

Write-Host "[OK] ADN-Loro-Existencias   -> cada hora de 06:00 a 22:00 | logs\existencias_FECHA.log" -ForegroundColor Green

# ─────────────────────────────────────────────────────────────────────────────
# TAREA 3: Imagenes ADN -> Web
#   Sincroniza: imagenes de productos (proceso lento)
#   Frecuencia: diario a las 23:00
# ─────────────────────────────────────────────────────────────────────────────
$img_inner = "`$log = '$log_dir\imagenes_' + (Get-Date -Format 'yyyy-MM-dd_HH-mm') + '.log'; & `"$script`" -Modo imagenes -LogFile `$log"

$action_img = New-ScheduledTaskAction `
    -Execute  $ps_exe `
    -Argument "-ExecutionPolicy Bypass -NonInteractive -WindowStyle Hidden -Command `"& { $img_inner }`"" `
    -WorkingDirectory $work_dir

$trigger_img = New-ScheduledTaskTrigger -Daily -At "23:00"

$settings_img = New-ScheduledTaskSettingsSet `
    -ExecutionTimeLimit (New-TimeSpan -Hours 3) `
    -MultipleInstances IgnoreNew `
    -StartWhenAvailable

Register-ScheduledTask `
    -TaskName    "ADN-Loro-Imagenes" `
    -Description "Imagenes de productos ADN -> Web (diario 23:00)" `
    -Action      $action_img `
    -Trigger     $trigger_img `
    -Settings    $settings_img `
    -RunLevel    Highest `
    -Force | Out-Null

Write-Host "[OK] ADN-Loro-Imagenes      -> diario 23:00               | logs\imagenes_FECHA.log" -ForegroundColor Green

# ─────────────────────────────────────────────────────────────────────────────
# TAREA 4: Pedidos Web -> ADN
#   Sincroniza: pedidos nuevos de WordPress a ADN, crea clientes si no existen
#   Frecuencia: cada 30 minutos (todo el dia)
# ─────────────────────────────────────────────────────────────────────────────
$ped_inner = "`$log = '$log_dir\pedidos_' + (Get-Date -Format 'yyyy-MM-dd_HH-mm') + '.log'; & `"$script`" -Modo pedidos -LogFile `$log"

$action_ped = New-ScheduledTaskAction `
    -Execute  $ps_exe `
    -Argument "-ExecutionPolicy Bypass -NonInteractive -WindowStyle Hidden -Command `"& { $ped_inner }`"" `
    -WorkingDirectory $work_dir

$trigger_ped = New-ScheduledTaskTrigger -Once -At (Get-Date "00:00")
$trigger_ped.Repetition = New-CimInstance `
    -CimClass (Get-CimClass -ClassName MSFT_TaskRepetitionPattern -Namespace Root/Microsoft/Windows/TaskScheduler) `
    -ClientOnly `
    -Property @{
        Interval          = "PT30M"
        Duration          = "P1D"
        StopAtDurationEnd = $false
    }

$settings_ped = New-ScheduledTaskSettingsSet `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 20) `
    -MultipleInstances IgnoreNew `
    -StartWhenAvailable

Register-ScheduledTask `
    -TaskName    "ADN-Loro-Pedidos" `
    -Description "Pedidos web -> ADN, crea clientes si no existen (cada 30 min)" `
    -Action      $action_ped `
    -Trigger     $trigger_ped `
    -Settings    $settings_ped `
    -RunLevel    Highest `
    -Force | Out-Null

Write-Host "[OK] ADN-Loro-Pedidos       -> cada 30 min (todo el dia)  | logs\pedidos_FECHA.log" -ForegroundColor Green

# ─────────────────────────────────────────────────────────────────────────────
# TAREA 5: Rotacion de Logs
#   Elimina archivos de log con mas de 30 dias
#   Frecuencia: diario a las 00:30
# ─────────────────────────────────────────────────────────────────────────────
$rot_cmd  = "Get-ChildItem '$log_dir' -Filter '*.log' | " +
            "Where-Object { `$_.LastWriteTime -lt (Get-Date).AddDays(-30) } | " +
            "Remove-Item -Force"

$action_rot = New-ScheduledTaskAction `
    -Execute  $ps_exe `
    -Argument "-ExecutionPolicy Bypass -NonInteractive -WindowStyle Hidden -Command `"$rot_cmd`"" `
    -WorkingDirectory $work_dir

$trigger_rot = New-ScheduledTaskTrigger -Daily -At "00:30"

$settings_rot = New-ScheduledTaskSettingsSet `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 5) `
    -MultipleInstances IgnoreNew

Register-ScheduledTask `
    -TaskName    "ADN-Loro-Logs-Rotate" `
    -Description "Elimina logs de sincronizacion con mas de 30 dias" `
    -Action      $action_rot `
    -Trigger     $trigger_rot `
    -Settings    $settings_rot `
    -RunLevel    Highest `
    -Force | Out-Null

Write-Host "[OK] ADN-Loro-Logs-Rotate   -> diario 00:30 (borra logs >30 dias)" -ForegroundColor Green

# ─────────────────────────────────────────────────────────────────────────────
Write-Host ""
Write-Host "======================================" -ForegroundColor Cyan
Write-Host "  Tareas ADN-Loro registradas" -ForegroundColor Cyan
Write-Host "======================================" -ForegroundColor Cyan
Get-ScheduledTask | Where-Object { $_.TaskName -like "ADN-Loro-*" } |
    Select-Object TaskName, State, @{N="Descripcion";E={$_.Description}} |
    Format-Table -AutoSize

Write-Host ""
Write-Host "Logs guardados en: $log_dir" -ForegroundColor Yellow
Write-Host "Para ver el ultimo log de pedidos:" -ForegroundColor Yellow
Write-Host "  Get-ChildItem '$log_dir\pedidos_*.log' | Sort LastWriteTime -Desc | Select -First 1 | Get-Content" -ForegroundColor Gray
