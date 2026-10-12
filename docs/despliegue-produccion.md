# Despliegue a producción — Dashboard Jefe de Zona

Guía para pasar el sistema al VPS (Ubuntu 24.04 + CloudPanel + Nginx + PHP 8.3
+ MySQL 8, solo accesible por Tailscale). Complementa
`.claude/rules/server-conventions.md`. Cualquier cambio de Nginx, PHP-FPM o
firewall pasa por revisión de `security-auditor` antes de aplicarse.

## 1. Base de datos MySQL (una sola vez)

```sql
CREATE DATABASE dashboard_jefe_zona CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'dashboard_app'@'localhost' IDENTIFIED BY '<contraseña larga y aleatoria>';
GRANT ALL PRIVILEGES ON dashboard_jefe_zona.* TO 'dashboard_app'@'localhost';
FLUSH PRIVILEGES;
```

- `bind-address = 127.0.0.1` en `mysqld.cnf` (nunca `0.0.0.0`).
- La base arranca **vacía**: no se migra nada del SQLite anterior.

## 2. `.env` del servidor

Parte de `.env.example` y ajusta, como mínimo:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<host-tailscale>
APP_LOCALE=es

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=dashboard_jefe_zona
DB_USERNAME=dashboard_app
DB_PASSWORD=<la del paso 1>

SESSION_SECURE_COOKIE=true

# Decisión del Jefe de Zona (oct 2026): una sola cuenta compartida, sin 2FA.
# Volver a true en cuanto existan cuentas individuales.
TWO_FACTOR_ENABLED=false

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

1. Entrar por la VPN e iniciar sesión con la cuenta creada.
2. Abrir una sección, arrastrar un Excel pequeño de prueba, revisar la vista
   previa y aplicarla; después deshacerla desde la pestaña "Cargas".
3. Exportar una tabla a Excel.
4. En RR.HH. > Personal, arrastrar el Excel de la Base de datos Zona Oriente y
   revisar la vista previa (sin aplicar si no es el definitivo).
5. Revisar `storage/logs/laravel.log` sin errores.

## 6. Respaldos

Igual que antes: `mysqldump` diario de `dashboard_jefe_zona` fuera del web
root, más un respaldo de `storage/app/private/secciones` (ahí viven los
documentos subidos; sin él, los registros de documentos quedan sin archivo).
