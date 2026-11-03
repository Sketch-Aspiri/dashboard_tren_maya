#Requires -Version 5.1
<#
.SYNOPSIS
    Restaura un respaldo hecho por backup.ps1 (REEMPLAZA la base de datos actual).

.EXAMPLE
    .\restaurar.ps1 -Respaldo D:\respaldos\dashboard_2026-11-02_2330.sql.gz
    .\restaurar.ps1 -Respaldo D:\respaldos\dashboard_2026-11-02_2330.sql.gz -Documentos D:\respaldos\documentos
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$Respaldo,

    # Carpeta "documentos" del respaldo; si se omite solo se restaura la base.
    [string]$Documentos
)

. (Join-Path $PSScriptRoot 'comun.ps1')
Add-Type -AssemblyName System.IO.Compression

Exigir-Administrador

if (-not (Test-Path -LiteralPath $Respaldo)) { throw "No existe el archivo $Respaldo" }

Write-Host ''
Write-Host 'ATENCION: esto borra la informacion actual del Dashboard y deja la del respaldo.' -ForegroundColor Red
Write-Host "Respaldo: $Respaldo"
$confirmacion = Read-Host 'Escriba RESTAURAR para continuar'
if ($confirmacion -cne 'RESTAURAR') { Write-Host 'Cancelado.'; exit 0 }

$puerto = Leer-Env 'DB_PORT'
$base = Leer-Env 'DB_DATABASE'
$claveRoot = (Get-Content -LiteralPath (Join-Path $Secretos 'mysql-root.txt') -Raw).Trim()
$mysql = Join-Path $MysqlDir 'bin\mysql.exe'
$archivoClaves = Join-Path $Secretos 'restaurar.cnf'
$sqlTemporal = Join-Path $env:TEMP ('dashboard_restaurar_' + [guid]::NewGuid().ToString('N') + '.sql')

try {
    Escribir-Paso 'Respaldo de seguridad de lo que hay ahora'
    & (Join-Path $PSScriptRoot 'backup.ps1') -Destino (Join-Path $Raiz 'respaldos-previos')
    if ($LASTEXITCODE -ne 0) { throw 'No se pudo respaldar el estado actual; se cancela para no perder datos.' }

    Escribir-Paso 'Deteniendo el sitio'
    Detener-Web

    Escribir-Paso 'Descomprimiendo el respaldo'
    $entrada = [IO.File]::OpenRead($Respaldo)
    $gz = New-Object IO.Compression.GZipStream($entrada, [IO.Compression.CompressionMode]::Decompress)
    $salida = [IO.File]::Create($sqlTemporal)
    try { $gz.CopyTo($salida) } finally { $salida.Dispose(); $gz.Dispose(); $entrada.Dispose() }

    Set-Content -LiteralPath $archivoClaves -Encoding ASCII -Value @('[client]', 'user=root', "password=$claveRoot", 'host=127.0.0.1', "port=$puerto")
    Proteger-Archivo $archivoClaves

    Escribir-Paso 'Cargando la base de datos'
    "DROP DATABASE IF EXISTS ``$base``; CREATE DATABASE ``$base`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" |
        & $mysql "--defaults-extra-file=$archivoClaves"
    if ($LASTEXITCODE -ne 0) { throw 'No se pudo recrear la base.' }

    # La redireccion de entrada la hace cmd para no cargar el archivo completo en memoria.
    cmd.exe /c "`"$mysql`" --defaults-extra-file=`"$archivoClaves`" --default-character-set=utf8mb4 $base < `"$sqlTemporal`""
    if ($LASTEXITCODE -ne 0) { throw 'La carga del respaldo fallo. Los datos previos estan en C:\dashboard\respaldos-previos.' }

    if ($Documentos) {
        Escribir-Paso 'Restaurando documentos'
        & robocopy $Documentos (Join-Path $App 'storage\app\private') /MIR /R:2 /W:3 /NFL /NDL /NJH /NJS /NP | Out-Null
        if ($LASTEXITCODE -ge 8) { throw 'No se pudieron copiar los documentos.' }
    }

    Escribir-Paso 'Arrancando el sitio'
    Invoke-Artisan @('optimize:clear')
    Invoke-Artisan @('config:cache')
    Invoke-Artisan @('route:cache')
    Invoke-Artisan @('view:cache')
    Iniciar-Web

    $instalacion = Leer-Instalacion
    if (Probar-Sitio ([int]$instalacion.puerto)) { Escribir-Ok 'Restauracion terminada y el sitio responde.' }
    else { Escribir-Aviso 'El sitio no respondio tras restaurar. Revise C:\dashboard\logs.' }
} finally {
    Remove-Item -LiteralPath $sqlTemporal -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $archivoClaves -Force -ErrorAction SilentlyContinue
    if (-not (Get-Service DashboardNginx -ErrorAction SilentlyContinue | Where-Object Status -eq 'Running')) { Iniciar-Web }
}
