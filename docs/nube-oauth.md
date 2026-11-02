# Registro de apps OAuth (OneDrive/SharePoint, Google Drive, Dropbox)

Para TI. El dashboard solo **lee** archivos de las nubes (permisos de solo
lectura); no puede modificar ni borrar nada en ellas. Cada proveedor requiere
registrar una "app" y entregarnos un **Client ID** y un **Client Secret**.

**Datos comunes**
- Dominio: `https://<dominio>` (el mismo del dashboard, con HTTPS válido).
- Las URIs de redirección deben coincidir **exactamente** (sin barra final):
  - Microsoft: `https://<dominio>/nube/microsoft/callback`
  - Google: `https://<dominio>/nube/google/callback`
  - Dropbox: `https://<dominio>/nube/dropbox/callback`
- Entregar las credenciales por canal seguro (gestor de contraseñas o
  directamente en el `.env` del servidor). **Nunca por chat, correo ni git.**
- Registrar **fecha de vencimiento del secret** y quién lo renueva.

## 1. Microsoft (Entra ID / Azure) — OneDrive personal, institucional y SharePoint
Portal: https://entra.microsoft.com → *Identidad → Aplicaciones → Registros de aplicaciones → Nuevo registro*.

1. Nombre: `Dashboard Jefe de Zona (lectura)`.
2. **Tipos de cuenta compatibles:** *Cuentas en cualquier directorio de la organización
   y cuentas personales de Microsoft* (necesario para leer OneDrive personal **e** institucional).
3. Tipo de plataforma **Web**, URI de redirección de Microsoft (arriba).
4. *Certificados y secretos → Nuevo secreto de cliente*. Copiar el **Valor** (solo se muestra una vez).
5. *Permisos de API → Microsoft Graph → Permisos delegados*:
   `offline_access`, `User.Read`, `Files.Read.All`, `Sites.Read.All`.
6. Para el tenant institucional, un administrador debe pulsar
   **Conceder consentimiento de administrador** (`Files.Read.All` / `Sites.Read.All`).
7. Entregar: **Application (client) ID** → `MICROSOFT_CLIENT_ID`,
   **secreto** → `MICROSOFT_CLIENT_SECRET`. `MICROSOFT_TENANT=common`.

## 2. Google Cloud — Google Drive
Consola: https://console.cloud.google.com

1. Proyecto nuevo (p. ej. `dashboard-jefe-zona`) → *APIs y servicios → Biblioteca* → habilitar **Google Drive API**.
2. *Pantalla de consentimiento OAuth*:
   - Si la organización usa Google Workspace: tipo **Interno**.
   - Si no: **Externo** y **publicar la app** (estado *En producción*).
   > En estado *Testing* los refresh tokens **vencen a los 7 días** y la sincronización se rompe.
   > `drive.readonly` es un alcance restringido: una app Externa pide verificación de Google.
3. Alcance: `https://www.googleapis.com/auth/drive.readonly`.
4. *Credenciales → Crear credenciales → ID de cliente OAuth* → tipo **Aplicación web**,
   URI de redirección de Google.
5. Entregar: **ID de cliente** → `GOOGLE_CLIENT_ID`, **secreto** → `GOOGLE_CLIENT_SECRET`.

## 3. Dropbox
Consola: https://www.dropbox.com/developers/apps → *Create app*.

1. API: *Scoped access*. Acceso: *Full Dropbox*. Nombre único (p. ej. `dashboard-jefe-zona`).
2. Pestaña *Permissions*: marcar `files.metadata.read`, `files.content.read`, `sharing.read` → *Submit*.
3. Pestaña *Settings*: agregar la URI de redirección de Dropbox.
4. Entregar: **App key** → `DROPBOX_CLIENT_ID`, **App secret** → `DROPBOX_CLIENT_SECRET`.

## Dónde se configuran
En el `.env` **del servidor** (ver `deploy/vps/env.produccion.example`):

```dotenv
MICROSOFT_CLIENT_ID=…       MICROSOFT_CLIENT_SECRET=…       MICROSOFT_TENANT=common
MICROSOFT_REDIRECT_URI=https://<dominio>/nube/microsoft/callback
GOOGLE_CLIENT_ID=…          GOOGLE_CLIENT_SECRET=…
GOOGLE_REDIRECT_URI=https://<dominio>/nube/google/callback
DROPBOX_CLIENT_ID=…         DROPBOX_CLIENT_SECRET=…
DROPBOX_REDIRECT_URI=https://<dominio>/nube/dropbox/callback
```
Después: `php artisan config:cache`. Comprobar sin imprimir secretos:
```bash
php artisan tinker --execute="foreach(['microsoft','google','dropbox'] as \$p) echo \$p.': '.(config(\"services.\$p.client_id\") && config(\"services.\$p.client_secret\") ? 'configurado' : 'FALTA').PHP_EOL;"
```

## Rotación y revocación
- Antes de que venza un secret: crear uno nuevo, actualizar `.env`, `config:cache`,
  y recién entonces borrar el viejo.
- Si un secret se expuso (chat, correo, log, git): **rotarlo de inmediato**.
- Revocar acceso de una cuenta: desde el dashboard (*Conexiones de nube → Desconectar*)
  y, en el proveedor, quitar la app de las aplicaciones autorizadas de esa cuenta.
