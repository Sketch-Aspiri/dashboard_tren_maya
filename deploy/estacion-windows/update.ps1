#Requires -Version 5.1
<#
.SYNOPSIS
    Actualiza el Dashboard de la estacion con un paquete nuevo (generado por build-release.ps1).

.DESCRIPTION
    Respalda, pone el sitio en mantenimiento, reemplaza el codigo (conserva .env y storage),
    aplica las migraciones, regenera cachés y vuelve a encender. El codigo anterior queda en
    C:\dashboard\app.anterior por si hay que volver atras.

    Ejecutar desde la carpeta del paquete NUEVO, como administrador.
#>
[CmdletBinding()]
param()

. (Join-Path $PSScriptRoot 'comun.ps1')

Exigir-Administrador

$instalacion = Leer-Instalacion
if (-not $instalacion) { throw 'No hay una instalacion previa en C:\dashboard. Use install.ps1.' }

$origenApp = Join-Path $PSScriptRoot 'app'
if (-not (Test-Path -LiteralPath $origenApp)) { throw "No se encuentra la carpeta 'app' junto a este script." }

$versionNueva = (Get-Content -LiteralPath (Join-Path $PSScriptRoot 'VERSION.txt') -ErrorAction SilentlyContinue | Select-Object -First 1)
Write-Host "Version instalada: $($instalacion.version)"
Write-Host "Version nueva:     $versionNueva"
if ($versionNueva -and $versionNueva -eq $instalacion.version) {
    $seguir = Read-Host 'Es la misma version. Escriba SI para reinstalarla de todos modos'
    if ($seguir -cne 'SI') { exit 0 }
}

Escribir-Paso 'Respaldo previo'
& (Join-Path $Scripts 'backup.ps1') -Destino $instalacion.destinoRespaldo
if ($LASTEXITCODE -ne 0) { throw 'El respaldo fallo; no se actualiza para no arriesgar datos.' }

Escribir-Paso 'Guardando el codigo actual (app.anterior)'
$anterior = Join-Path $Raiz 'app.anterior'
if (Test-Path -LiteralPath $anterior) { Remove-Item -LiteralPath $anterior -Recurse -Force }
& robocopy $App $anterior /E /XD (Join-Path $App 'storage') /NFL /NDL /NJH /NJS /NP | Out-Null
if ($LASTEXITCODE -ge 8) { throw 'No se pudo guardar el codigo anterior.' }

try {
    Escribir-Paso 'Modo mantenimiento'
    Invoke-Artisan @('down', '--retry=60')
    Detener-Web

    Escribir-Paso 'Reemplazando el codigo (se conservan .env y storage)'
    # Rutas completas: un nombre suelto "storage" tambien excluiria carpetas de vendor/.
    & robocopy $origenApp $App /MIR /XD (Join-Path $App 'storage') (Join-Path $origenApp 'storage') /XF .env /NFL /NDL /NJH /NJS /NP | Out-Null
    if ($LASTEXITCODE -ge 8) { throw 'No se pudo copiar el codigo nuevo.' }

    # Carpetas de trabajo que /MIR pudo haber quitado.
    foreach ($sub in @('storage\framework\cache\data', 'storage\framework\sessions', 'storage\framework\views', 'storage\logs', 'bootstrap\cache')) {
        New-Item -ItemType Directory -Force -Path (Join-Path $App $sub) | Out-Null
    }
    & icacls $App /grant '*S-1-5-19:(OI)(CI)M' /T /C /Q | Out-Null

    Escribir-Paso 'Migraciones y cachés'
    Invoke-Artisan @('optimize:clear')
    Invoke-Artisan @('migrate', '--force', '--seed')
    Invoke-Artisan @('config:cache')
    Invoke-Artisan @('route:cache')
    Invoke-Artisan @('view:cache')
    Invoke-Artisan @('up')

    Copy-Item -Path (Join-Path $PSScriptRoot '*.ps1') -Destination $Scripts -Force
    Iniciar-Web

    if (-not (Probar-Sitio ([int]$instalacion.puerto))) { throw 'El sitio no respondio despues de actualizar.' }

    $instalacion.version = $versionNueva
    $instalacion | ConvertTo-Json | Set-Content -LiteralPath $Marcador -Encoding UTF8
    Escribir-Ok "Actualizado a $versionNueva."
} catch {
    Write-Host ''
    Write-Host "LA ACTUALIZACION FALLO: $($_.Exception.Message)" -ForegroundColor Red
    Write-Host 'Para volver a la version anterior:' -ForegroundColor Yellow
    Write-Host "  1. robocopy `"$anterior`" `"$App`" /MIR /XD `"$App\storage`" /XF .env"
    Write-Host '  2. Si la base cambio, restaurar el respaldo con restaurar.ps1'
    Write-Host '  3. php artisan up  (desde C:\dashboard\app)'
    exit 1
}
