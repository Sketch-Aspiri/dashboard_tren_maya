#Requires -Version 5.1
<#
.SYNOPSIS
    Arma el paquete de instalacion para las estaciones (se corre UNA vez, en la PC de desarrollo).

.DESCRIPTION
    1. Toma el codigo comprometido en Git (HEAD), sin vendor ni .env.
    2. Instala dependencias de produccion (composer --no-dev) y compila los assets (npm run build).
    3. Reune los componentes (PHP, Nginx, MySQL, NSSM, LibreOffice...) en la carpeta 'componentes'.
    4. Genera salida\dashboard-estacion-<version>\ y su .zip, listos para copiar a cada PC.

    Requiere en esta PC: git, php, composer y npm en el PATH, y conexion a internet.

.EXAMPLE
    .\build-release.ps1
    .\build-release.ps1 -VersionLibreOffice 25.8.7
#>
[CmdletBinding()]
param(
    [string]$VersionNginx = '1.28.0',
    [string]$VersionLibreOffice = '25.8.7',

    # Carpeta CORTA a proposito: dentro de OneDrive las rutas de vendor\ pasan de 260 caracteres
    # y Windows PowerShell 5.1 no puede comprimirlas.
    [string]$Salida = 'C:\dashboard-build',

    [switch]$SinZip,

    # Anota en componentes.sha256.json el hash de los componentes que aun no estan fijados.
    # Usar SOLO despues de revisar a mano que los archivos descargados son los legitimos.
    [switch]$RegistrarHashes
)

$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
Add-Type -AssemblyName System.IO.Compression.FileSystem

$aqui        = $PSScriptRoot
$repo        = (Resolve-Path (Join-Path $aqui '..\..')).Path
$componentes = Join-Path $aqui 'componentes'
$salida      = $Salida
New-Item -ItemType Directory -Force -Path $salida | Out-Null

function Paso([string]$texto) { Write-Host ''; Write-Host "==> $texto" -ForegroundColor Cyan }

function Exigir-Comando([string]$nombre) {
    if (-not (Get-Command $nombre -ErrorAction SilentlyContinue)) { throw "No se encontro '$nombre' en el PATH." }
}

function Descargar([string]$url, [string]$archivo) {
    $destino = Join-Path $componentes $archivo
    if (Test-Path -LiteralPath $destino) { Write-Host "    ya existe: $archivo"; return }

    Write-Host "    descargando $archivo ..."
    $ProgressPreference = 'SilentlyContinue'
    Invoke-WebRequest -Uri $url -OutFile $destino -UseBasicParsing -UserAgent 'Mozilla/5.0'
}

# Prueba varias direcciones hasta que una funcione; devuelve $false si ninguna.
function Descargar-Con-Respaldo([string[]]$urls, [string]$archivo) {
    foreach ($url in $urls) {
        try {
            Descargar $url $archivo
            if ((Get-Item -LiteralPath (Join-Path $componentes $archivo)).Length -gt 50KB) { return $true }
        } catch {
            Write-Host "    no se pudo con $url" -ForegroundColor Yellow
        }
        Remove-Item -LiteralPath (Join-Path $componentes $archivo) -Force -ErrorAction SilentlyContinue
    }

    return $false
}

function Ejecutar([string]$exe, [string[]]$argumentos, [string]$directorio) {
    Push-Location $directorio
    try {
        & $exe @argumentos
        if ($LASTEXITCODE -ne 0) { throw "$exe $($argumentos -join ' ') fallo (codigo $LASTEXITCODE)" }
    } finally { Pop-Location }
}

# Integridad de los componentes ----------------------------------------------
# Cada componente tiene su SHA-256 fijado en componentes.sha256.json (versionado en Git).
# Un archivo con hash distinto, o sin hash fijado, detiene el armado del paquete.
$archivoHashes = Join-Path $aqui 'componentes.sha256.json'
$hashesFijados = @{}
if (Test-Path -LiteralPath $archivoHashes) {
    (Get-Content -LiteralPath $archivoHashes -Raw -Encoding UTF8 | ConvertFrom-Json).PSObject.Properties |
        ForEach-Object { $hashesFijados[$_.Name] = $_.Value }
}

function Verificar-Componente([string]$archivo, [string]$firmante = '') {
    $ruta = Join-Path $componentes $archivo
    $hash = (Get-FileHash -LiteralPath $ruta -Algorithm SHA256).Hash.ToLowerInvariant()

    if ($hashesFijados.ContainsKey($archivo)) {
        if ($hashesFijados[$archivo] -ne $hash) {
            throw "$archivo no coincide con el hash fijado (esperado $($hashesFijados[$archivo]), obtenido $hash). Posible archivo alterado o version nueva: revisela y use -RegistrarHashes si es legitima."
        }
    } elseif ($RegistrarHashes) {
        $hashesFijados[$archivo] = $hash
        Write-Host "    hash fijado para ${archivo}: $hash" -ForegroundColor Yellow
    } else {
        throw "$archivo no tiene hash fijado (SHA-256 $hash). Verifiquelo contra el sitio oficial y ejecute con -RegistrarHashes."
    }

    if ($firmante) {
        $firma = Get-AuthenticodeSignature -LiteralPath $ruta
        if ($firma.Status -ne 'Valid' -or $firma.SignerCertificate.Subject -notmatch $firmante) {
            throw "$archivo no tiene una firma Authenticode valida de '$firmante' (estado: $($firma.Status))."
        }
    }
    Write-Host "    verificado: $archivo"
}

function Guardar-Hashes {
    $ordenado = [ordered]@{}
    $hashesFijados.GetEnumerator() | Sort-Object Name | ForEach-Object { $ordenado[$_.Name] = $_.Value }
    $ordenado | ConvertTo-Json | Set-Content -LiteralPath $archivoHashes -Encoding UTF8
}

# 1. Verificaciones ----------------------------------------------------------
Paso 'Verificando herramientas'
foreach ($comando in @('git', 'php', 'composer', 'npm')) { Exigir-Comando $comando }

$cambios = & git -C $repo status --porcelain
if ($cambios) {
    Write-Host '    !!  Hay cambios sin commit. El paquete se arma con lo COMMITEADO (HEAD); lo demas no entra.' -ForegroundColor Yellow
    $seguir = Read-Host '    Escriba SI para continuar de todos modos'
    if ($seguir -cne 'SI') { exit 1 }
}

$commit = (& git -C $repo rev-parse --short HEAD).Trim()
$etiqueta = (& git -C $repo describe --tags --always).Trim()
$version = '{0}-{1}' -f $etiqueta, (Get-Date -Format 'yyyyMMdd')
$paquete = Join-Path $salida "est-$version"
Write-Host "    version: $version (commit $commit)"

# 2. Componentes -------------------------------------------------------------
Paso 'Reuniendo componentes'
New-Item -ItemType Directory -Force -Path $componentes | Out-Null

# PHP 8.4: el composer.lock (symfony/* 8.x) exige PHP >= 8.4.1. Se borran versiones viejas de PHP
# que hayan quedado de otras corridas para que no entren al paquete.
Get-ChildItem -LiteralPath $componentes -Filter 'php-*.zip' -ErrorAction SilentlyContinue |
    Where-Object { $_.Name -notlike 'php-8.4-*' } | Remove-Item -Force
Descargar 'https://windows.php.net/downloads/releases/latest/php-8.4-nts-Win32-vs17-x64-latest.zip' 'php-8.4-nts-Win32-vs17-x64-latest.zip'
Descargar "https://nginx.org/download/nginx-$VersionNginx.zip" "nginx-$VersionNginx.zip"
Descargar 'https://aka.ms/vs/17/release/vc_redist.x64.exe' 'vc_redist.x64.exe'
Descargar 'https://curl.se/ca/cacert.pem' 'cacert.pem'
Descargar "https://download.documentfoundation.org/libreoffice/stable/$VersionLibreOffice/win/x86_64/LibreOffice_${VersionLibreOffice}_Win_x86-64.msi" "LibreOffice_${VersionLibreOffice}_Win_x86-64.msi"

# NSSM: enlace directo y, si falla, el paquete de Chocolatey (es un zip con tools\win64\nssm.exe).
if (-not (Get-ChildItem -LiteralPath $componentes -Filter 'nssm-*.zip' -ErrorAction SilentlyContinue)) {
    [void](Descargar-Con-Respaldo @(
        'https://nssm.cc/release/nssm-2.24.zip',
        'https://community.chocolatey.org/api/v2/package/nssm/2.24.101.20180116'
    ) 'nssm-2.24.zip')
}

# MySQL bloquea las descargas automaticas: se baja a mano una sola vez.
$faltan = @()
if (-not (Get-ChildItem -LiteralPath $componentes -Filter 'mysql-8.0*-winx64.zip' -ErrorAction SilentlyContinue)) {
    $faltan += "MySQL 8.0 (ZIP, Windows x64) : https://dev.mysql.com/downloads/mysql/8.0.html  -> 'Windows (x86, 64-bit), ZIP Archive' (archivo mysql-8.0.XX-winx64.zip)"
}
if (-not (Get-ChildItem -LiteralPath $componentes -Filter 'nssm-*.zip' -ErrorAction SilentlyContinue)) {
    $faltan += "NSSM 2.24 (ZIP)               : https://nssm.cc/release/nssm-2.24.zip  (si no abre, pruebe https://community.chocolatey.org/api/v2/package/nssm/2.24.101.20180116 y renombre el archivo a nssm-2.24.zip)"
}
if ($faltan) {
    Write-Host ''
    Write-Host "Descargue estos archivos a mano y copielos a:  $componentes" -ForegroundColor Yellow
    $faltan | ForEach-Object { Write-Host "  - $_" }
    throw 'Faltan componentes. Cuando los copie, vuelva a ejecutar build-release.ps1.'
}

Paso 'Verificando integridad de los componentes'
foreach ($zip in (Get-ChildItem -Path (Join-Path $componentes '*') -Include 'php-8.4-*.zip', 'nginx-*.zip', 'mysql-8.0*-winx64.zip', 'nssm-*.zip' -File)) {
    Verificar-Componente $zip.Name
}
Verificar-Componente 'cacert.pem'
Verificar-Componente 'vc_redist.x64.exe' 'Microsoft'
Verificar-Componente "LibreOffice_${VersionLibreOffice}_Win_x86-64.msi" 'Document Foundation'
if ($RegistrarHashes) { Guardar-Hashes }

# 3. Codigo ------------------------------------------------------------------
Paso 'Extrayendo el codigo de Git'
if (Test-Path -LiteralPath $paquete) { Remove-Item -LiteralPath $paquete -Recurse -Force }
New-Item -ItemType Directory -Force -Path $paquete | Out-Null
$app = Join-Path $paquete 'app'

$zipCodigo = Join-Path $env:TEMP "dashboard_codigo_$commit.zip"
if (Test-Path -LiteralPath $zipCodigo) { Remove-Item -LiteralPath $zipCodigo -Force }
& git -C $repo archive --format=zip -o $zipCodigo HEAD
if ($LASTEXITCODE -ne 0) { throw 'git archive fallo.' }
[IO.Compression.ZipFile]::ExtractToDirectory($zipCodigo, $app)
Remove-Item -LiteralPath $zipCodigo -Force

Paso 'Dependencias de produccion (composer) y assets (npm)'
Ejecutar 'composer' @('install', '--no-dev', '--optimize-autoloader', '--no-interaction', '--prefer-dist') $app
Ejecutar 'npm' @('ci') $app
Ejecutar 'npm' @('run', 'build') $app

if (-not (Test-Path -LiteralPath (Join-Path $app 'public\build\manifest.json'))) { throw 'npm run build no genero public\build\manifest.json.' }

# Lo que una estacion no necesita.
foreach ($sobra in @('node_modules', 'tests', 'deploy', '.claude', '.github', 'phpunit.xml', 'docs')) {
    $ruta = Join-Path $app $sobra
    if (Test-Path -LiteralPath $ruta) { Remove-Item -LiteralPath $ruta -Recurse -Force }
}

# Orden de las filas del oficio de asistencia (el repo lo ignora: vive solo en esta PC).
$orden = Join-Path $repo 'storage\app\private\imports\documentos-autogenerados\orden_oficio.json'
if (Test-Path -LiteralPath $orden) {
    $destinoOrden = Join-Path $app 'storage\app\private\imports\documentos-autogenerados'
    New-Item -ItemType Directory -Force -Path $destinoOrden | Out-Null
    Copy-Item -LiteralPath $orden -Destination $destinoOrden
    Write-Host '    orden_oficio.json incluido'
} else {
    Write-Host '    !!  No se encontro orden_oficio.json; el oficio saldra con el orden por defecto.' -ForegroundColor Yellow
}

# 4. Paquete -----------------------------------------------------------------
Paso 'Armando el paquete'
Copy-Item -LiteralPath $componentes -Destination (Join-Path $paquete 'componentes') -Recurse
Copy-Item -LiteralPath (Join-Path $aqui 'plantillas') -Destination (Join-Path $paquete 'plantillas') -Recurse
foreach ($archivo in @('comun.ps1', 'install.ps1', 'update.ps1', 'backup.ps1', 'restaurar.ps1', 'desinstalar.ps1', 'LEEME.md', 'estaciones.csv')) {
    Copy-Item -LiteralPath (Join-Path $aqui $archivo) -Destination $paquete
}
Set-Content -LiteralPath (Join-Path $paquete 'VERSION.txt') -Value $version -Encoding ASCII

if (-not $SinZip) {
    $zipFinal = "$paquete.zip"
    if (Test-Path -LiteralPath $zipFinal) { Remove-Item -LiteralPath $zipFinal -Force }
    [IO.Compression.ZipFile]::CreateFromDirectory($paquete, $zipFinal)
    Write-Host "    zip: $zipFinal"
}

Write-Host ''
Write-Host "Paquete listo: $paquete" -ForegroundColor Green
Write-Host 'Copielo a la PC de la estacion (USB) y siga LEEME.md.'
