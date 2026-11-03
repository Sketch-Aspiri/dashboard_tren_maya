# Despliegue en VPS Hostinger — guía para TI

Fase 0 de `docs/plan-sincronizacion-nube.md`. Deja el dashboard **tal como está
hoy** corriendo en el VPS (sin cambios de esquema), con dominio público, cron y
worker, y listo para recibir las credenciales OAuth de las nubes.

**Stack fijo:** Hostinger KVM 2 (2 vCPU / 8 GB) · Ubuntu 24.04 · CloudPanel ·
Nginx · PHP 8.3-FPM · MySQL 8 local · Let's Encrypt.
Todo cambio de Nginx, firewall o PHP-FPM lo revisa `security-auditor`.

| Archivo | Para qué |
|---|---|
| `env.produccion.example` | Plantilla del `.env` de producción (solo placeholders) |
| `deploy.sh` | Despliegue con respaldo previo y modo mantenimiento |
| `supervisor-dashboard.conf` | Worker de cola bajo supervisor |
| `cron.txt` | Entrada única de `schedule:run` + respaldo diario |
| `nginx-seguridad.conf` | Cabeceras, límite de subida, bloqueo de archivos sensibles |
| `migrar-desde-local.md` | Copia de BD y documentos desde el servidor local |
| `verificar-migracion.php` | Compara conteos y archivos local vs VPS |
| `../../docs/nube-oauth.md` | Cómo registrar las apps OAuth y entregar credenciales |

## Datos que TI debe definir antes de empezar
- Dominio/subdominio del dashboard (`<dominio>`), p. ej. `dashboard.<organización>.mx`.
- Usuario del sitio en CloudPanel (`<SITIO_USER>`) y su ruta (`<RUTA_SITIO>`).
- Un destino externo para respaldos (otro servidor, bucket S3/B2, etc.).

## V1. Provisión del servidor
1. VPS con imagen Ubuntu 24.04 + CloudPanel. Actualizar: `apt update && apt upgrade`.
2. CloudPanel: sitio **PHP 8.3 / Laravel**, usuario propio, root `…/public`.
3. PHP (CloudPanel → PHP Settings): `upload_max_filesize=100M`, `post_max_size=110M`,
   `memory_limit=512M`, `max_execution_time=120`.
   Extensiones: `zip gd intl mbstring xml bcmath mysql curl fileinfo`.
   PHP CLI (worker): `memory_limit=1G`.
4. MySQL: crear base y usuario (ver `docs/despliegue-produccion.md` §1);
   `bind-address = 127.0.0.1`.
5. Paquetes: `apt install supervisor libreoffice-writer fonts-noto git unzip fail2ban unattended-upgrades`
   (LibreOffice solo para los oficios de asistencia). Node 20+ para `npm run build`
   (o compilar en local y subir `public/build`).
6. Zona horaria del sistema y NTP activos.

## V2. Red y seguridad (dominio público)
1. **Tailscale** instalado en el VPS y en los equipos de quienes administran.
2. **Firewall** (panel de Hostinger **y** `ufw`; hacerlo conectado por Tailscale
   y comprobar antes de cerrar la sesión):
   ```bash
   ufw default deny incoming && ufw default allow outgoing
   ufw allow 80/tcp && ufw allow 443/tcp
   ufw allow in on tailscale0 to any port 22 proto tcp
   ufw allow in on tailscale0 to any port 8443 proto tcp   # CloudPanel
   ufw enable
   ```
   Resultado esperado desde internet: **solo 80 y 443**. 22, 8443 y 3306 cerrados.
3. SSH: `PasswordAuthentication no`, `PermitRootLogin no`, solo llaves.
4. DNS: registro A de `<dominio>` → IP del VPS. CloudPanel → Let's Encrypt (renovación automática).
5. Pegar `nginx-seguridad.conf` en el vhost del sitio.
6. **Requisito antes de abrir el DNS** (si falta uno, no se publica):
   - [ ] `TWO_FACTOR_ENABLED=true` (al activarlo, **todas** las cuentas —incluida la del Jefe— registran su 2FA en la siguiente petición: coordinarlo con él presente; recuperación con `php artisan app:reset-two-factor <correo>`)
   - [ ] una cuenta por persona (sin cuenta compartida), creadas en *Usuarios*: Edificio y Estación (varias por estación)
   - [ ] secciones aprobadas para estaciones y filas/documentos etiquetados (probado con una cuenta Estación de prueba, que se borra después)
   - [ ] `APP_DEBUG=false`, `APP_ENV=production`, `SESSION_SECURE_COOKIE=true`
   - [ ] limitador de login probado (muchos intentos fallidos → `429`)
   - [ ] `fail2ban` activo (sshd + nginx)

## V3. Aplicación
1. Deploy key de GitHub (solo lectura) para el usuario del sitio; `git clone` en `<RUTA_SITIO>`.
2. `cp deploy/vps/env.produccion.example .env`, rellenar, `chmod 600 .env`,
   `php artisan key:generate`. **El `.env` real nunca se manda por chat ni correo.**
3. Primera vez: `composer install --no-dev -o && npm ci && npm run build`,
   luego restaurar datos (`migrar-desde-local.md`) **o** `php artisan migrate --force --seed`
   + `php artisan app:create-zone-chief` si se arranca vacío.
4. Siguientes despliegues: `bash deploy/vps/deploy.sh`.
5. `php artisan storage:link` solo si algún disco público lo necesita (los documentos
   están en disco privado y se sirven por controlador).

## V4. Segundo plano
- **Cron:** instalar `cron.txt` para el usuario del sitio (una sola entrada de `schedule:run`).
- **Worker:** copiar `supervisor-dashboard.conf` a `/etc/supervisor/conf.d/`, reemplazar
  `<SITIO_USER>`/`<RUTA_SITIO>`, `supervisorctl reread && supervisorctl update`.
- Comprobar: `crontab -l`, `supervisorctl status`, `php artisan schedule:list`.

## V5. Migración de datos
Seguir `migrar-desde-local.md` y terminar con `verificar-migracion.php` en `OK`.
**Los cambios de esquema de las fases siguientes (F1+) no se aplican hasta cerrar F0.**

## V6. Respaldos y monitoreo
- Respaldo diario de BD (`cron.txt`) + `rclone`/`rsync` de `~/respaldos` y
  `storage/app/private` a un destino **externo**. Snapshot semanal de Hostinger.
- **Prueba de restauración mensual** en una base temporal.
- Monitoreo: `GET <dominio>/up` en un servicio de uptime; alerta de disco > 80 %;
  revisar `failed_jobs`; `supervisorctl status`.

## OAuth de las nubes
TI registra las apps y entrega Client ID/Secret por canal seguro. Pasos exactos en
`docs/nube-oauth.md`. Se guardan en el `.env` del servidor y luego
`php artisan config:cache`. El código que los consume llega en la fase F2; hasta
entonces las variables solo quedan listas.

## Cierre (checklist de aceptación de F0)
- [ ] `https://<dominio>/up` responde 200 con certificado válido; `http://` redirige a `https://`.
- [ ] Desde internet solo responden 80/443 (probar con `nmap` desde fuera).
- [ ] Login con 2FA; 6 intentos fallidos → `429`.
- [ ] `verificar-migracion.php` → `OK`.
- [ ] Subir un Excel de prueba, aplicarlo, deshacerlo, exportar.
- [ ] `crontab -l` y `supervisorctl status` correctos; `storage/logs` sin errores.
- [ ] Respaldo diario generado y **restauración probada**.
- [ ] Servidor local intacto y marcado como respaldo hasta una semana sin incidencias.
