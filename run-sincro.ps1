<#
.SYNOPSIS
    Wrapper para sincro-adn-loro.ps1 que calcula el nombre de log con fecha/hora.
    Llamado por las tareas programadas de Windows.
#>
param(
    [string]$Modo,
    [string]$LogPrefix  # Ej: "C:\ADN Software\sincro\logs\catalogo_"
)

$log = $LogPrefix + (Get-Date -Format 'yyyy-MM-dd_HH-mm') + '.log'
$script = Join-Path $PSScriptRoot "sincro-adn-loro.ps1"

& $script -Modo $Modo -LogFile $log
