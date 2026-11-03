#Requires -Version 5.1
<#
.SYNOPSIS
    Quita el Dashboard de esta PC (servicios, tarea de respaldo, regla de firewall y carpeta C:\dashboard).

.DESCRIPTION
    Sirve para repetir una instalacion que fallo a medias o para retirar una PC de prueba.
    BORRA la base de datos y los documentos de esta PC. Los respaldos de -DestinoRespaldo NO se tocan,
    salvo la carpeta por defecto C:\dashboard\respaldos (que esta dentro de C:\dashboard).
#>
[CmdletBinding()]
param(
    # Omite la pregunta de confirmacion.
    [switch]$Confirmar
)

. (Join-Path $PSScriptRoot 'comun.ps1')

Exigir-Administrador

# Este script puede estar corriendo desde C:\dashboard\scripts; se trabaja desde otra carpeta.
Set-Location $env:SystemDrive\

Write-Host ''
Write-Host 'Se va a QUITAR el Dashboard de esta PC y BORRAR C:\dashboard (base de datos y documentos incluidos).' -ForegroundColor Red
if (-not $Confirmar) {
    $respuesta = Read-Host 'Escriba DESINSTALAR para continuar'
    if ($respuesta -cne 'DESINSTALAR') { Write-Host 'Cancelado.'; exit 0 }
}

Escribir-Paso 'Deteniendo y quitando servicios'
$servicios = @('DashboardNginx', 'DashboardPHP1', 'DashboardPHP2', 'DashboardPHP3', 'DashboardMySQL')

foreach ($servicio in $servicios) {
    if (Get-Service -Name $servicio -ErrorAction SilentlyContinue) {
        Stop-Service -Name $servicio -Force -ErrorAction SilentlyContinue
    }
}
Start-Sleep -Seconds 3

foreach ($servicio in $servicios) {
    if (Get-Service -Name $servicio -ErrorAction SilentlyContinue) {
        if (Test-Path -LiteralPath $Nssm) { & $Nssm remove $servicio confirm | Out-Null } else { & sc.exe delete $servicio | Out-Null }
        Escribir-Ok "Servicio $servicio quitado"
    }
}

Escribir-Paso 'Tarea de respaldo y firewall'
Unregister-ScheduledTask -TaskName 'Dashboard - respaldo nocturno' -Confirm:$false -ErrorAction SilentlyContinue
Get-NetFirewallRule -DisplayName 'Dashboard Tren Maya (web)' -ErrorAction SilentlyContinue | Remove-NetFirewallRule

Escribir-Paso 'Borrando C:\dashboard'
if (Test-Path -LiteralPath $Raiz) {
    # Los procesos pueden tardar en soltar los archivos; se reintenta.
    for ($intento = 1; $intento -le 5 -and (Test-Path -LiteralPath $Raiz); $intento++) {
        & cmd.exe /c "rmdir /s /q `"$Raiz`"" 2>$null
        if (Test-Path -LiteralPath $Raiz) { Start-Sleep -Seconds 3 }
    }
}

if (Test-Path -LiteralPath $Raiz) {
    Escribir-Aviso "No se pudo borrar $Raiz por completo. Reinicie la PC y borre la carpeta a mano."
    exit 1
}

Escribir-Ok 'Listo. Ya se puede volver a ejecutar install.ps1.'
