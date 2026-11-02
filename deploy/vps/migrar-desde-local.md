# Migrar los datos del servidor local al VPS

El servidor local **no se toca**: se trabaja con una copia y el local queda como
respaldo y plan de regreso hasta que el VPS esté validado.
Supuesto: hay **un solo** servidor local con MySQL 8 (si hubiera varias
estaciones con bases propias, unirlas es otro trabajo).

## A. En el servidor local (Windows)

1. Aviso a los usuarios y congelar cambios (nadie edita durante la copia).
2. Manifiesto de conteos:
   ```powershell
   php deploy/vps/verificar-migracion.php manifiesto > manifiesto.json
   ```
3. Volcado de la base (usa la ruta de `mysqldump` de tu instalación MySQL):
   ```powershell
   mysqldump -u dashboard_app -p --single-transaction --routines --hex-blob `
     --default-character-set=utf8mb4 dashboard_jefe_zona > dashboard.sql
   ```
4. Documentos:
   ```powershell
   tar -czf documentos.tar.gz -C storage\app private
   ```
5. Anota el tamaño de `dashboard.sql` y `documentos.tar.gz` (para verificar la copia)
   y calcula huellas: `Get-FileHash dashboard.sql, documentos.tar.gz -Algorithm SHA256`.

## B. Transferir (solo por Tailscale)

```powershell
scp dashboard.sql documentos.tar.gz manifiesto.json <usuario-sitio>@<ip-tailscale-vps>:~/migracion/
```
En el VPS: `sha256sum ~/migracion/*` y comparar con las huellas del paso A.5.

## C. En el VPS (usuario del sitio)

1. El código ya debe estar desplegado (`LEEME.md`, pasos V1-V4) y la base
   `dashboard_jefe_zona` creada **vacía**, con el sitio en mantenimiento
   (`php artisan down`).
2. Importar:
   ```bash
   mysql -u dashboard_app -p dashboard_jefe_zona < ~/migracion/dashboard.sql
   ```
3. Documentos (conservan la misma ruta `secciones/{id}/...`):
   ```bash
   tar -xzf ~/migracion/documentos.tar.gz -C storage/app
   ```
   Permisos solo para el usuario del sitio: `chmod -R u+rwX,go-rwx storage/app/private`.
4. Migraciones pendientes (si el código del VPS es más nuevo que el local):
   `php artisan migrate --force` (el respaldo previo lo hace `deploy.sh`).
5. Verificar:
   ```bash
   php deploy/vps/verificar-migracion.php verificar ~/migracion/manifiesto.json
   ```
   Debe terminar en `OK`. Si hay diferencias, **no se abre el sitio**.
6. Usuarios: conservar cuentas, pero forzar nueva contraseña y alta de 2FA
   (`TWO_FACTOR_ENABLED=true`). Crear una cuenta por persona.
7. `php artisan config:cache route:cache view:cache` → `php artisan up`.
8. Borrar `~/migracion/` del VPS cuando todo esté validado (contiene datos).

## D. Plan de regreso
Mientras el VPS no esté validado, el servidor local sigue siendo el sistema
vigente. No se apaga ni se borra hasta cerrar la lista de `LEEME.md` §Cierre.
