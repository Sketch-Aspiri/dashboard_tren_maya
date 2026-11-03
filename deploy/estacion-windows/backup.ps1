#Requires -Version 5.1
<#
.SYNOPSIS
    Respaldo de la base de datos y de los documentos de la estacion.

.DESCRIPTION
    - dashboard_AAAA-MM-DD_HHmm.sql.gz  : volcado completo de MySQL (se conservan -Dias dias).
    - documentos\                        : copia espejo de storage\app\private (documentos, fotos, oficios).
    Lo ejecuta solo la tarea programada "Dashboard - respaldo nocturno"; tambien se puede correr a mano.
#>
[CmdletBinding()]
param(
    [string]$Destino,
    [int]$Dias = 14
)

. (Join-Path $PSScriptRoot 'comun.ps1')
Add-Type -AssemblyName System.IO.Compression

Exigir-Administrador

if (-not $Destino) {
    $instalacion = Leer-Instalacion
    $Destino = if ($instalacion -and $instalacion.destinoRespaldo) { $instalacion.destinoRespaldo } else { Join-Path $Raiz 'respaldos' }
}

New-Item -ItemType Directory -Force -Path $Destino, $Logs | Out-Null
$bitacora = Join-Path $Logs 'backup.log'

function Registrar([string]$mensaje) {
    $linea = '{0}  {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $mensaje
    Add-Content -LiteralPath $bitacora -Value $linea -Encoding UTF8
    Write-Host $linea
}

$archivoClaves = Join-Path $Secretos 'mysqldump.cnf'
$sqlTemporal = Join-Path $env:TEMP ('dashboard_' + [guid]::NewGuid().ToString('N') + '.sql')

try {
    if (-not (Test-Path -LiteralPath $Destino)) { throw "No se puede escribir en $Destino (unidad desconectada?)." }

    $puerto = Leer-Env 'DB_PORT'
    $clave = Leer-Env 'DB_PASSWORD'
    $base = Leer-Env 'DB_DATABASE'
    $usuario = Leer-Env 'DB_USERNAME'

    # La clave va en un archivo protegido, no en la linea de comandos.
    Set-Content -LiteralPath $archivoClaves -Encoding ASCII -Value @('[client]', "user=$usuario", "password=$clave", 'host=127.0.0.1', "port=$puerto")
    Proteger-Archivo $archivoClaves

    $mysqldump = Join-Path $MysqlDir 'bin\mysqldump.exe'
    & $mysqldump "--defaults-extra-file=$archivoClaves" --single-transaction --routines --default-character-set=utf8mb4 `
        "--result-file=$sqlTemporal" $base
    if ($LASTEXITCODE -ne 0) { throw "mysqldump fallo (codigo $LASTEXITCODE)." }

    # Comprobacion minima: el volcado debe traer tablas.
    if (-not (Select-String -LiteralPath $sqlTemporal -Pattern 'CREATE TABLE' -Quiet)) { throw 'El volcado no contiene tablas; se descarta.' }

    $nombre = 'dashboard_{0}.sql.gz' -f (Get-Date -Format 'yyyy-MM-dd_HHmm')
    $destinoGz = Join-Path $Destino $nombre
    $entrada = [IO.File]::OpenRead($sqlTemporal)
    $salida = [IO.File]::Create($destinoGz)
    $gz = New-Object IO.Compression.GZipStream($salida, [IO.Compression.CompressionMode]::Compress)
    try { $entrada.CopyTo($gz) } finally { $gz.Dispose(); $salida.Dispose(); $entrada.Dispose() }

    Registrar ('Base respaldada: {0} ({1:N1} MB)' -f $nombre, ((Get-Item -LiteralPath $destinoGz).Length / 1MB))

    $documentos = Join-Path $App 'storage\app\private'
    & robocopy $documentos (Join-Path $Destino 'documentos') /MIR /R:2 /W:3 /NFL /NDL /NJH /NJS /NP | Out-Null
    if ($LASTEXITCODE -ge 8) { throw "No se pudieron copiar los documentos (robocopy $LASTEXITCODE)." }
    Registrar 'Documentos sincronizados.'

    Get-ChildItem -LiteralPath $Destino -Filter 'dashboard_*.sql.gz' |
        Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-$Dias) } |
        ForEach-Object { Remove-Item -LiteralPath $_.FullName -Force; Registrar "Respaldo antiguo eliminado: $($_.Name)" }

    Registrar 'Respaldo terminado correctamente.'
} catch {
    Registrar "ERROR: $($_.Exception.Message)"
    exit 1
} finally {
    Remove-Item -LiteralPath $sqlTemporal -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $archivoClaves -Force -ErrorAction SilentlyContinue
}
