# Despliegue a producción — Dashboard Jefe de Zona

Guía para pasar el sistema al VPS (Ubuntu 24.04 + CloudPanel + Nginx + PHP 8.3
+ MySQL 8, dominio público por HTTPS; SSH y CloudPanel solo por Tailscale).
**La guía operativa paso a paso para TI está en `deploy/vps/LEEME.md`**; este
documento es la referencia de configuración. Complementa
`.cursor/rules/server-conventions.mdc`. Cualquier cambio de Nginx, PHP-FPM o
firewall pasa por revisión de `security-auditor` antes de aplicarse.

## 1. Base de datos MySQL (una sola vez)

```sql
CREATE DATABASE dashboard_jefe_zona CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'dashboard_app'@'localhost' IDENTIFIED BY '<contraseña larga y aleatoria>';
GRANT ALL PRIVILEGES ON dashboard_jefe_zona.* TO 'dashboard_app'@'localhost';
FLUSH PRIVILEGES;
```

- `bind-address = 127.0.0.1` en `mysqld.cnf` (nunca `0.0.0.0`).
- Para llevar los datos del servidor local usa `deploy/vps/migrar-desde-local.md`
  (importar el dump en esta base vacía y verificar con
  `deploy/vps/verificar-migracion.php`). Si se arranca sin datos, la base queda
  vacía y se usa `--seed` (paso 4).

## 2. `.env` del servidor

Parte de `.env.example` y ajusta, como mínimo:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<dominio-del-dashboard>
APP_LOCALE=es

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=dashboard_jefe_zona
DB_USERNAME=dashboard_app
DB_PASSWORD=<la del paso 1>

SESSION_SECURE_COOKIE=true

# OBLIGATORIO true con dominio público (cuentas individuales + 2FA).
TWO_FACTOR_ENABLED=true

SECCIONES_SYNC_MINUTOS=15
QUEUE_CONNECTION=database

SECCIONES_MAX_ARCHIVO_KB=102400

# IA opcional. Vacío = apagada (sin asistente ni "Extraer con IA").
ANTHROPIC_API_KEY=
ANTHROPIC_MODEL=claude-sonnet-5-5
```

- `php artisan key:generate` la primera vez.
- **Importante (IA):** si el servidor tiene una variable de entorno de sistema
  `ANTHROPIC_API_KEY`, Laravel la usa aunque `.env` la deje vacía. Verifica con
  `php artisan tinker --execute="echo config('services.anthropic.key') ? 'IA encendida' : 'IA apagada';"`.
- Antes de encender la IA: el asistente solo envía resultados de consultas
  (sin columnas con datos personales y con redacción de CURP/RFC/NSS/teléfonos/
  correos), pero "Extraer con IA" envía el **documento completo** a Anthropic.
  Requiere visto bueno del Área de TIC.

## 3. Límites de subida (archivos de hasta 100 MB)

PHP-FPM (pool del sitio en CloudPanel → PHP Settings):

```ini
upload_max_filesize = 100M
post_max_size = 110M
memory_limit = 512M
max_execution_time = 120
```

Nginx (vhost del sitio, dentro de `server { }`):

```nginx
client_max_body_size 110M;
# El asistente responde en streaming: no acumular la respuesta.
location = /dashboard/asistente {
    proxy_buffering off;
    fastcgi_buffering off;
    try_files $uri /index.php?$query_string;
}
```

(`X-Accel-Buffering: no` ya lo manda la aplicación; la regla de Nginx es un
respaldo.)

## 4. Despliegue

Desde el segundo despliegue usa `bash deploy/vps/deploy.sh` (respaldo previo +
mantenimiento + migración + cachés). Manual / primera vez:

```bash
git pull origin master
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force --seed      # solo la primera vez lleva --seed
php artisan app:create-zone-chief       # primera vez: correo del Jefe de Zona y contraseña nueva
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

- `--seed` crea roles, estaciones y el menú inicial; es idempotente y no
  vuelve a crear secciones que el administrador haya renombrado o quitado.
- `app:create-zone-chief` es interactivo: la contraseña nunca queda en scripts
  ni en el historial.

## 5. Verificación después del despliegue

1. Entrar a `https://<dominio>` e iniciar sesión (con 2FA) con la cuenta creada.
2. Abrir una sección, arrastrar un Excel pequeño de prueba, revisar la vista
   previa y aplicarla; después deshacerla desde la pestaña "Cargas".
3. Exportar una tabla a Excel.
4. En RR.HH. > Personal, arrastrar el Excel de la Base de datos Zona Oriente y
   revisar la vista previa (sin aplicar si no es el definitivo).
5. Revisar `storage/logs/laravel.log` sin errores.

## 5b. Procesos en segundo plano (obligatorio)

- **Cron** del usuario del sitio con la entrada única de `schedule:run`
  (`deploy/vps/cron.txt`): sin él no corre la sincronización de nube.
- **Worker de cola** bajo supervisor (`deploy/vps/supervisor-dashboard.conf`).
- `libreoffice-writer` + `fonts-noto` y `ASISTENCIA_SOFFICE_PATH=/usr/bin/soffice`
  para los oficios de asistencia.
- Credenciales OAuth de nubes: `docs/nube-oauth.md`.

## 6. Respaldos

Igual que antes: `mysqldump` diario de `dashboard_jefe_zona` fuera del web
root, más un respaldo de `storage/app/private/secciones` (ahí viven los
documentos subidos; sin él, los registros de documentos quedan sin archivo).
