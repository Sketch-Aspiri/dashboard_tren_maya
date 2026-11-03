# Funciones y rutas compartidas por install, update, backup y restaurar.
# Se usa con:  . "$PSScriptRoot\comun.ps1"

$ErrorActionPreference = 'Stop'

$script:Raiz       = 'C:\dashboard'
$script:App        = Join-Path $Raiz 'app'
$script:Srv        = Join-Path $Raiz 'srv'
$script:Php        = Join-Path $Srv 'php\php.exe'
$script:PhpCgi     = Join-Path $Srv 'php\php-cgi.exe'
$script:Nginx      = Join-Path $Srv 'nginx\nginx.exe'
$script:MysqlDir   = Join-Path $Srv 'mysql'
$script:Nssm       = Join-Path $Srv 'nssm.exe'
$script:Config     = Join-Path $Raiz 'configuracion'
$script:PhpIni     = Join-Path $Config 'php.ini'
$script:Logs       = Join-Path $Raiz 'logs'
$script:Secretos   = Join-Path $Raiz 'secretos'
$script:Scripts    = Join-Path $Raiz 'scripts'
$script:Datos      = Join-Path $Raiz 'datos\mysql'
$script:Marcador   = Join-Path $Raiz 'instalacion.json'

$script:ServiciosWeb = @('DashboardNginx', 'DashboardPHP1', 'DashboardPHP2', 'DashboardPHP3')
$script:ServicioDb   = 'DashboardMySQL'

function Exigir-Administrador {
    $identidad = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($identidad)

    if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw 'Este script debe ejecutarse como administrador (clic derecho en PowerShell > Ejecutar como administrador).'
    }
}

function Escribir-Paso([string]$texto) {
    Write-Host ''
    Write-Host "==> $texto" -ForegroundColor Cyan
}

function Escribir-Ok([string]$texto) {
    Write-Host "    OK  $texto" -ForegroundColor Green
}

function Escribir-Aviso([string]$texto) {
    Write-Host "    !!  $texto" -ForegroundColor Yellow
}

function Nuevo-Secreto([int]$largo = 32) {
    $caracteres = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789'.ToCharArray()
    $bytes = New-Object byte[] $largo
    $rng = New-Object Security.Cryptography.RNGCryptoServiceProvider
    $rng.GetBytes($bytes)
    $rng.Dispose()

    return -join ($bytes | ForEach-Object { $caracteres[$_ % $caracteres.Length] })
}

# Valor de una clave del .env de la aplicacion (sin comillas), o $null.
function Leer-Env([string]$clave) {
    $archivo = Join-Path $App '.env'
    $linea = Get-Content -LiteralPath $archivo -Encoding UTF8 | Where-Object { $_ -match "^$clave=" } | Select-Object -First 1

    if (-not $linea) { return $null }

    return ($linea -replace "^$clave=", '').Trim().Trim('"', "'")
}

# Ejecuta PHP con el php.ini de la instalacion.
function Invoke-Php([string[]]$argumentos) {
    & $Php -c $PhpIni @argumentos

    if ($LASTEXITCODE -ne 0) {
        throw "PHP termino con error (codigo $LASTEXITCODE): php $($argumentos -join ' ')"
    }
}

function Invoke-Artisan([string[]]$argumentos) {
    Push-Location $App
    try { Invoke-Php (@('artisan') + $argumentos) }
    finally { Pop-Location }
}

function Detener-Web {
    foreach ($servicio in $ServiciosWeb) {
        if (Get-Service -Name $servicio -ErrorAction SilentlyContinue) {
            Stop-Service -Name $servicio -Force -ErrorAction SilentlyContinue
        }
    }
}

function Iniciar-Web {
    # Los procesos PHP primero, el servidor web al final.
    foreach ($servicio in @('DashboardPHP1', 'DashboardPHP2', 'DashboardPHP3', 'DashboardNginx')) {
        Start-Service -Name $servicio
    }
}

function Esperar-Puerto([int]$puerto, [int]$segundos = 60) {
    $limite = (Get-Date).AddSeconds($segundos)

    while ((Get-Date) -lt $limite) {
        $cliente = New-Object Net.Sockets.TcpClient
        try {
            $cliente.Connect('127.0.0.1', $puerto)
            $cliente.Close()
            return $true
        } catch {
            Start-Sleep -Milliseconds 700
        } finally {
            $cliente.Dispose()
        }
    }

    return $false
}

# Pide el login y confirma que la aplicacion responde.
function Probar-Sitio([int]$puerto) {
    try {
        $respuesta = Invoke-WebRequest -Uri "http://127.0.0.1:$puerto/login" -UseBasicParsing -TimeoutSec 60
        return $respuesta.StatusCode -eq 200
    } catch {
        return $false
    }
}

function Leer-Instalacion {
    if (-not (Test-Path -LiteralPath $Marcador)) { return $null }

    return Get-Content -LiteralPath $Marcador -Raw -Encoding UTF8 | ConvertFrom-Json
}

# Deja un archivo legible solo por Administradores y SYSTEM.
function Proteger-Archivo([string]$ruta) {
    & icacls $ruta /inheritance:r /grant:r '*S-1-5-32-544:(F)' '*S-1-5-18:(F)' | Out-Null
}

# Crea el archivo YA restringido y despues escribe en el: la clave nunca queda legible por otros.
function Escribir-Archivo-Protegido([string]$ruta, [string[]]$lineas) {
    New-Item -ItemType File -Force -Path $ruta | Out-Null
    Proteger-Archivo $ruta
    Set-Content -LiteralPath $ruta -Encoding ASCII -Value $lineas
}

# Permisos minimos de la cuenta de servicio (LocalService, S-1-5-19).
# Lectura/ejecucion sobre todo C:\dashboard y escritura SOLO donde la app lo necesita
# (storage, bootstrap\cache, logs y datos de MySQL). Asi un fallo en la app no puede
# reescribir el codigo, los ejecutables de los servicios ni leer los secretos.
# Idempotente: tambien limpia los permisos recursivos de versiones anteriores del instalador.
function Aplicar-Permisos-Servicio {
    $ls = '*S-1-5-19'
    $admin = '*S-1-5-32-544'
    $sistema = '*S-1-5-18'

    & icacls $Raiz /reset /T /C /Q | Out-Null
    & icacls $Raiz /inheritance:r /grant:r "${admin}:(OI)(CI)(F)" "${sistema}:(OI)(CI)(F)" "${ls}:(OI)(CI)(RX)" | Out-Null

    # Secretos y scripts: solo Administradores y SYSTEM (los scripts se ejecutan como administrador).
    foreach ($carpeta in @($Secretos, $Scripts)) {
        if (Test-Path -LiteralPath $carpeta) {
            & icacls $carpeta /inheritance:r /grant:r "${admin}:(OI)(CI)(F)" "${sistema}:(OI)(CI)(F)" | Out-Null
        }
    }

    $escribibles = @(
        (Join-Path $App 'storage'), (Join-Path $App 'bootstrap\cache'), $Logs, $Datos
    )
    foreach ($carpeta in $escribibles) {
        New-Item -ItemType Directory -Force -Path $carpeta | Out-Null
        & icacls $carpeta /grant "${ls}:(OI)(CI)M" /T /C /Q | Out-Null
    }

    $env = Join-Path $App '.env'
    if (Test-Path -LiteralPath $env) {
        Proteger-Archivo $env
        & icacls $env /grant "${ls}:(R)" | Out-Null
    }
}

# Primer .zip / .msi / .exe de la carpeta de componentes que coincida con el patron.
function Buscar-Componente([string]$carpeta, [string]$patron) {
    # Orden descendente: si hay dos versiones, se usa la de numero mayor.
    return Get-ChildItem -LiteralPath $carpeta -Filter $patron -File -ErrorAction SilentlyContinue | Sort-Object Name -Descending | Select-Object -First 1
}
