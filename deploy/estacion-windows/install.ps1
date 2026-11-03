#Requires -Version 5.1
<#
.SYNOPSIS
    Instala el Dashboard Jefe de Zona en la PC de una estacion (Windows 10/11).

.EXAMPLE
    .\install.ps1 -Estacion "Bacalar"
    .\install.ps1 -Estacion "Tulum" -IP 192.168.1.50 -Puerto 8080

.NOTES
    Ejecutar como administrador, una sola vez por PC. Para actualizar usar update.ps1.
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateSet('Bacalar', 'Chetumal', 'Felipe Carrillo Puerto', 'Kohunlich', 'Limones',
        'Playa del Carmen', 'Puerto Morelos', 'Tulum', 'Tulum Aeropuerto', 'Edificio Zonal Este')]
    [string]$Estacion,

    # IP con la que otras PCs de la estacion abriran el sitio. Si se omite, se detecta.
    [string]$IP,

    [int]$Puerto = 80,

    # Puerto interno de MySQL (solo accesible desde la propia PC).
    [int]$PuertoMysql = 3307,

    # Carpeta donde el respaldo nocturno deja sus archivos (idealmente un disco externo).
    [string]$DestinoRespaldo = 'C:\dashboard\respaldos'
)

. (Join-Path $PSScriptRoot 'comun.ps1')

Add-Type -AssemblyName System.IO.Compression.FileSystem

$paquete     = $PSScriptRoot
$componentes = Join-Path $paquete 'componentes'
$plantillas  = Join-Path $paquete 'plantillas'
$origenApp   = Join-Path $paquete 'app'
$puertosPhp  = @(9001, 9002, 9003)

function Expandir-Componente([string]$zip, [string]$destino) {
    $temporal = Join-Path $env:TEMP ('dashboard_' + [guid]::NewGuid().ToString('N'))
    [IO.Compression.ZipFile]::ExtractToDirectory($zip, $temporal)

    $hijos = @(Get-ChildItem -LiteralPath $temporal)
    $origen = if ($hijos.Count -eq 1 -and $hijos[0].PSIsContainer) { $hijos[0].FullName } else { $temporal }

    New-Item -ItemType Directory -Force -Path $destino | Out-Null
    & robocopy $origen $destino /E /NFL /NDL /NJH /NJS /NP | Out-Null
    if ($LASTEXITCODE -ge 8) { throw "No se pudo copiar $zip a $destino" }

    Remove-Item -LiteralPath $temporal -Recurse -Force
}

function Aplicar-Plantilla([string]$plantilla, [string]$destino, [hashtable]$valores) {
    $texto = Get-Content -LiteralPath $plantilla -Raw -Encoding UTF8

    foreach ($clave in $valores.Keys) {
        $texto = $texto.Replace('{{' + $clave + '}}', [string]$valores[$clave])
    }

    # UTF-8 sin BOM: Laravel y nginx no toleran el BOM al inicio del archivo.
    [IO.File]::WriteAllText($destino, $texto, (New-Object Text.UTF8Encoding($false)))
}

function Nuevo-Servicio([string]$nombre, [string]$exe, [string]$argumentos, [string]$directorio, [string]$descripcion) {
    & $Nssm install $nombre $exe | Out-Null
    & $Nssm set $nombre AppParameters $argumentos | Out-Null
    & $Nssm set $nombre AppDirectory $directorio | Out-Null
    & $Nssm set $nombre DisplayName "Dashboard Tren Maya - $nombre" | Out-Null
    & $Nssm set $nombre Description $descripcion | Out-Null
    & $Nssm set $nombre Start SERVICE_AUTO_START | Out-Null
    & $Nssm set $nombre AppExit Default Restart | Out-Null
    & $Nssm set $nombre AppRestartDelay 5000 | Out-Null
    & $Nssm set $nombre AppStdout (Join-Path $Logs "$nombre.log") | Out-Null
    & $Nssm set $nombre AppStderr (Join-Path $Logs "$nombre.log") | Out-Null
    & $Nssm set $nombre AppRotateFiles 1 | Out-Null
    & $Nssm set $nombre AppRotateBytes 10485760 | Out-Null
    # Cuenta de servicio sin privilegios (no SYSTEM).
    & $Nssm set $nombre ObjectName 'NT AUTHORITY\LocalService' '' | Out-Null
}

function Detectar-Ip {
    $configuracion = Get-NetIPConfiguration | Where-Object { $_.IPv4DefaultGateway -ne $null -and $_.NetAdapter.Status -eq 'Up' } | Select-Object -First 1

    if ($configuracion) { return $configuracion.IPv4Address.IPAddress }

    return $null
}

# ---------------------------------------------------------------------------
Exigir-Administrador
Escribir-Paso "Instalacion del Dashboard - estacion $Estacion"

# 1. Verificaciones previas -------------------------------------------------
Escribir-Paso 'Verificando la PC'

if (-not [Environment]::Is64BitOperatingSystem) { throw 'Se requiere Windows de 64 bits.' }
if (Test-Path -LiteralPath $App) { throw "Ya existe una instalacion en $App. Para actualizar use update.ps1." }
if (-not (Test-Path -LiteralPath $origenApp)) { throw "No se encuentra la carpeta 'app' junto a este script. Use el paquete completo generado por build-release.ps1." }

$enUso = Get-NetTCPConnection -State Listen -LocalPort $Puerto -ErrorAction SilentlyContinue
if ($enUso) { throw "El puerto $Puerto ya esta en uso en esta PC (puede ser IIS u otro programa). Use -Puerto 8080 o libere el puerto." }

$enUsoDb = Get-NetTCPConnection -State Listen -LocalPort $PuertoMysql -ErrorAction SilentlyContinue
if ($enUsoDb) { throw "El puerto $PuertoMysql ya esta en uso. Use -PuertoMysql con otro numero." }

if (-not $IP) { $IP = Detectar-Ip }
if (-not $IP) { throw 'No se pudo detectar la IP de la PC. Indiquela con -IP 192.168.x.x' }

$disco = Get-PSDrive -Name ($Raiz.Substring(0, 1))
if ($disco.Free -lt 15GB) { throw 'Se necesitan al menos 15 GB libres en el disco C:.' }

$zipPhp   = Buscar-Componente $componentes 'php-*.zip'
$zipNginx = Buscar-Componente $componentes 'nginx-*.zip'
$zipMysql = Buscar-Componente $componentes 'mysql-*.zip'
$zipNssm  = Buscar-Componente $componentes 'nssm-*.zip'
$vcRedist = Buscar-Componente $componentes 'vc_redist*.exe'
$msiOffice = Buscar-Componente $componentes 'LibreOffice_*.msi'

foreach ($par in @(@('PHP', $zipPhp), @('Nginx', $zipNginx), @('MySQL', $zipMysql), @('NSSM', $zipNssm))) {
    if (-not $par[1]) { throw "Falta el componente $($par[0]) en la carpeta 'componentes'." }
}

Escribir-Ok "IP del sitio: $IP  |  puerto: $Puerto"

# 2. Carpetas ---------------------------------------------------------------
Escribir-Paso 'Creando carpetas'
foreach ($carpeta in @($Raiz, $Srv, $Config, $Logs, $Secretos, $Scripts, $Datos, $DestinoRespaldo)) {
    New-Item -ItemType Directory -Force -Path $carpeta | Out-Null
}
Start-Transcript -Path (Join-Path $Logs 'install.log') -Append | Out-Null

# 3. Componentes ------------------------------------------------------------
Escribir-Paso 'Instalando componentes (puede tardar varios minutos)'

if ($vcRedist) {
    $vcInstalado = (Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64' -ErrorAction SilentlyContinue).Installed
    if ($vcInstalado -ne 1) {
        Start-Process -FilePath $vcRedist.FullName -ArgumentList '/install', '/quiet', '/norestart' -Wait
        Escribir-Ok 'Visual C++ Redistributable instalado'
    }
}

Expandir-Componente $zipPhp.FullName (Join-Path $Srv 'php')
Expandir-Componente $zipNginx.FullName (Join-Path $Srv 'nginx')
Expandir-Componente $zipMysql.FullName $MysqlDir

$temporalNssm = Join-Path $env:TEMP ('dashboard_nssm_' + [guid]::NewGuid().ToString('N'))
[IO.Compression.ZipFile]::ExtractToDirectory($zipNssm.FullName, $temporalNssm)
$nssmExe = Get-ChildItem -LiteralPath $temporalNssm -Recurse -Filter nssm.exe | Where-Object { $_.FullName -match 'win64' } | Select-Object -First 1
if (-not $nssmExe) { throw 'No se encontro nssm.exe (win64) dentro del componente NSSM.' }
Copy-Item -LiteralPath $nssmExe.FullName -Destination $Nssm
Remove-Item -LiteralPath $temporalNssm -Recurse -Force

$cacert = Buscar-Componente $componentes 'cacert.pem'
if ($cacert) { Copy-Item -LiteralPath $cacert.FullName -Destination (Join-Path $Srv 'php\cacert.pem') }
Escribir-Ok 'PHP, Nginx, MySQL y NSSM listos'

# LibreOffice (convierte los oficios .docx a PDF)
$soffice = @('C:\Program Files\LibreOffice\program\soffice.exe', 'C:\Program Files (x86)\LibreOffice\program\soffice.exe') |
    Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1

if (-not $soffice) {
    if (-not $msiOffice) { throw "LibreOffice no esta instalado y falta el instalador (LibreOffice_*.msi) en 'componentes'." }
    Escribir-Paso 'Instalando LibreOffice'
    Start-Process msiexec.exe -ArgumentList '/i', "`"$($msiOffice.FullName)`"", '/qn', '/norestart' -Wait
    $soffice = 'C:\Program Files\LibreOffice\program\soffice.exe'
    if (-not (Test-Path -LiteralPath $soffice)) { throw 'La instalacion de LibreOffice no termino correctamente.' }
}
Escribir-Ok "LibreOffice: $soffice"

# 4. Configuracion ----------------------------------------------------------
Escribir-Paso 'Escribiendo la configuracion'
$dirPhp = Join-Path $Srv 'php'
$temp = Join-Path $Raiz 'temp'
New-Item -ItemType Directory -Force -Path $temp | Out-Null

$comun = @{
    LOGS = $Logs.Replace('\', '/'); PHP_DIR = $dirPhp; TEMP = $temp
    PUERTO = $Puerto; PUBLIC = (Join-Path $App 'public').Replace('\', '/')
    MYSQL_DIR = $MysqlDir.Replace('\', '/'); DATOS = $Datos.Replace('\', '/'); DB_PORT = $PuertoMysql
}

Aplicar-Plantilla (Join-Path $plantillas 'php.ini') $PhpIni $comun
Aplicar-Plantilla (Join-Path $plantillas 'nginx.conf') (Join-Path $Srv 'nginx\conf\nginx.conf') $comun
Aplicar-Plantilla (Join-Path $plantillas 'my.ini') (Join-Path $Config 'my.ini') $comun
Escribir-Ok 'php.ini, nginx.conf y my.ini'

# 5. MySQL ------------------------------------------------------------------
Escribir-Paso 'Creando la base de datos'
$mysqld = Join-Path $MysqlDir 'bin\mysqld.exe'
$mysql = Join-Path $MysqlDir 'bin\mysql.exe'
$myIni = Join-Path $Config 'my.ini'

& $mysqld "--defaults-file=$myIni" --initialize-insecure --console
if ($LASTEXITCODE -ne 0) { throw 'MySQL no pudo inicializar su carpeta de datos. Revise C:\dashboard\logs\mysql-error.log' }

Nuevo-Servicio $ServicioDb $mysqld "--defaults-file=$myIni --console" $MysqlDir 'Base de datos MySQL del Dashboard (solo local)'
& $Nssm set $ServicioDb AppStopMethodConsole 30000 | Out-Null

# La cuenta de servicio necesita escribir en la carpeta de datos antes de arrancar.
& icacls $Raiz /grant '*S-1-5-19:(OI)(CI)M' /T /C /Q | Out-Null
Start-Service $ServicioDb
if (-not (Esperar-Puerto $PuertoMysql 90)) { throw 'MySQL no arranco. Revise C:\dashboard\logs\mysql-error.log' }

$claveRoot = Nuevo-Secreto 32
$claveApp = Nuevo-Secreto 32

$sql = @"
ALTER USER 'root'@'localhost' IDENTIFIED BY '$claveRoot';
CREATE DATABASE dashboard_jefe_zona CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'dashboard_app'@'localhost' IDENTIFIED BY '$claveApp';
GRANT ALL PRIVILEGES ON dashboard_jefe_zona.* TO 'dashboard_app'@'localhost';
FLUSH PRIVILEGES;
"@
# La instruccion va por la entrada estandar: la clave nunca aparece en la linea de comandos.
$sql | & $mysql -u root -h 127.0.0.1 -P $PuertoMysql --protocol=TCP
if ($LASTEXITCODE -ne 0) { throw 'No se pudo crear la base de datos.' }

Set-Content -LiteralPath (Join-Path $Secretos 'mysql-root.txt') -Value $claveRoot -Encoding ASCII
Proteger-Archivo (Join-Path $Secretos 'mysql-root.txt')
Escribir-Ok 'Base dashboard_jefe_zona creada (la clave de root queda en C:\dashboard\secretos)'

# 6. Aplicacion -------------------------------------------------------------
Escribir-Paso 'Copiando la aplicacion'
& robocopy $origenApp $App /E /NFL /NDL /NJH /NJS /NP | Out-Null
if ($LASTEXITCODE -ge 8) { throw 'No se pudo copiar la aplicacion.' }

foreach ($sub in @('storage\app\private', 'storage\app\public', 'storage\framework\cache\data', 'storage\framework\sessions',
        'storage\framework\views', 'storage\logs', 'bootstrap\cache')) {
    New-Item -ItemType Directory -Force -Path (Join-Path $App $sub) | Out-Null
}

Aplicar-Plantilla (Join-Path $plantillas 'env.estacion') (Join-Path $App '.env') @{
    APP_NAME = "Dashboard - Estación $Estacion"
    APP_URL = "http://$IP" + $(if ($Puerto -ne 80) { ":$Puerto" } else { '' })
    DB_PORT = $PuertoMysql; DB_PASSWORD = $claveApp; SOFFICE = $soffice
}
Proteger-Archivo (Join-Path $App '.env')
# El servicio (LocalService) debe poder leer el .env.
& icacls (Join-Path $App '.env') /grant '*S-1-5-19:(R)' | Out-Null

Invoke-Artisan @('key:generate', '--force')
Invoke-Artisan @('migrate', '--force', '--seed')

$ordenOficio = Join-Path $App 'storage\app\private\imports\documentos-autogenerados\orden_oficio.json'
if (Test-Path -LiteralPath $ordenOficio) { Invoke-Artisan @('asistencia:importar-orden-oficio') }

Escribir-Paso 'Cuenta del Jefe de Zona (se pide el correo y una contrasena nueva)'
Write-Host '    La contrasena debe tener 8 caracteres o mas y escribirse DOS veces igual.'
Write-Host '    Al escribirla no se ve nada en pantalla (es normal).'

$cuentaCreada = $false
for ($intento = 1; $intento -le 5 -and -not $cuentaCreada; $intento++) {
    Push-Location $App
    try { & $Php -c $PhpIni artisan app:create-zone-chief } finally { Pop-Location }
    $cuentaCreada = ($LASTEXITCODE -eq 0)

    if (-not $cuentaCreada) { Escribir-Aviso "No se creo la cuenta (intento $intento de 5). Intente de nuevo." }
}
if (-not $cuentaCreada) {
    throw 'No se pudo crear la cuenta del Jefe de Zona. Ejecute desinstalar.ps1 e instale otra vez.'
}

# 7. Servicios web ----------------------------------------------------------
Escribir-Paso 'Registrando servicios de Windows'
$n = 1
foreach ($puertoPhp in $puertosPhp) {
    Nuevo-Servicio "DashboardPHP$n" $PhpCgi "-b 127.0.0.1:$puertoPhp -c $PhpIni" $App 'Proceso PHP del Dashboard'
    & $Nssm set "DashboardPHP$n" AppEnvironmentExtra 'PHP_FCGI_MAX_REQUESTS=500' | Out-Null
    & $Nssm set "DashboardPHP$n" DependOnService $ServicioDb | Out-Null
    $n++
}
Nuevo-Servicio 'DashboardNginx' $Nginx "-p $($Srv.Replace('\', '/'))/nginx" (Join-Path $Srv 'nginx') 'Servidor web del Dashboard'

Copy-Item -Path (Join-Path $paquete '*.ps1') -Destination $Scripts -Force
& icacls $Raiz /grant '*S-1-5-19:(OI)(CI)M' /T /C /Q | Out-Null
& icacls $Scripts /inheritance:r /grant:r '*S-1-5-32-544:(OI)(CI)(F)' '*S-1-5-18:(OI)(CI)(F)' | Out-Null

Invoke-Artisan @('config:cache')
Invoke-Artisan @('route:cache')
Invoke-Artisan @('view:cache')

Iniciar-Web
Escribir-Ok 'Servicios iniciados'

# 8. Firewall, energia y respaldo ------------------------------------------
Escribir-Paso 'Firewall, energia y respaldo nocturno'
Get-NetFirewallRule -DisplayName 'Dashboard Tren Maya (web)' -ErrorAction SilentlyContinue | Remove-NetFirewallRule
# Solo equipos de la misma red local pueden abrir el sitio; nunca internet. MySQL no se abre.
New-NetFirewallRule -DisplayName 'Dashboard Tren Maya (web)' -Direction Inbound -Protocol TCP -LocalPort $Puerto `
    -RemoteAddress LocalSubnet -Action Allow -Profile Any | Out-Null
Escribir-Ok "Firewall: puerto $Puerto solo para la red local"

& powercfg /change standby-timeout-ac 0 | Out-Null
& powercfg /change hibernate-timeout-ac 0 | Out-Null
Escribir-Ok 'La PC ya no se suspende con corriente'

$accion = New-ScheduledTaskAction -Execute 'powershell.exe' `
    -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$Scripts\backup.ps1`" -Destino `"$DestinoRespaldo`""
$disparador = New-ScheduledTaskTrigger -Daily -At '23:30'
$ajustes = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries
Register-ScheduledTask -TaskName 'Dashboard - respaldo nocturno' -Action $accion -Trigger $disparador -Settings $ajustes `
    -User 'SYSTEM' -RunLevel Highest -Force | Out-Null
Escribir-Ok "Respaldo diario a las 23:30 en $DestinoRespaldo"

# 9. Prueba final -----------------------------------------------------------
Escribir-Paso 'Probando el sitio'
if (-not (Probar-Sitio $Puerto)) {
    Escribir-Aviso 'El sitio no respondio. Revise C:\dashboard\logs (nginx-error.log, php-error.log y los DashboardPHP*.log).'
    Stop-Transcript | Out-Null
    exit 1
}

@{ estacion = $Estacion; ip = $IP; puerto = $Puerto; puertoMysql = $PuertoMysql; destinoRespaldo = $DestinoRespaldo
   version = (Get-Content -LiteralPath (Join-Path $paquete 'VERSION.txt') -ErrorAction SilentlyContinue | Select-Object -First 1)
   instalada = (Get-Date -Format 'yyyy-MM-dd HH:mm') } | ConvertTo-Json | Set-Content -LiteralPath $Marcador -Encoding UTF8

$url = "http://$IP" + $(if ($Puerto -ne 80) { ":$Puerto" } else { '' })
Write-Host ''
Write-Host '=====================================================' -ForegroundColor Green
Write-Host " Instalacion terminada - estacion $Estacion" -ForegroundColor Green
Write-Host " Direccion para las PCs de la estacion:  $url" -ForegroundColor Green
Write-Host '=====================================================' -ForegroundColor Green
Write-Host ' Pendiente: reservar esta IP en el router (o fijarla en Windows) para que no cambie.'
Stop-Transcript | Out-Null
