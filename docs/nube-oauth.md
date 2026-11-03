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
- Las URIs de arriba también aparecen, ya calculadas y con botón *Copiar*, en
  la app: menú de usuario → **Conexiones de nube** (solo Administrador), en la
  tarjeta de cada proveedor ("Redirect URI"). Es la fuente de verdad: dásela a TI tal cual.
- Entregar las credenciales por canal seguro (gestor de contraseñas). El
  Administrador las captura en **Conexiones de nube**; no hace falta tocar el
  `.env` del servidor. **Nunca por chat, correo ni git.**
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
7. Entregar: **Application (client) ID** (→ campo *Client ID*) y **secreto**
   (→ campo *Client Secret*). Tenant: `common` (campo *Tenant*).

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
5. Entregar: **ID de cliente** (→ *Client ID*) y **secreto** (→ *Client Secret*).

## 3. Dropbox
Consola: https://www.dropbox.com/developers/apps → *Create app*.

1. API: *Scoped access*. Acceso: *Full Dropbox*. Nombre único (p. ej. `dashboard-jefe-zona`).
2. Pestaña *Permissions*: marcar `files.metadata.read`, `files.content.read`, `sharing.read` → *Submit*.
3. Pestaña *Settings*: agregar la URI de redirección de Dropbox.
4. Entregar: **App key** (→ *Client ID*) y **App secret** (→ *Client Secret*).

## Dónde se configuran
**Desde la app (lo normal).** Entra como Administrador → menú de usuario →
**Conexiones de nube**. Cada proveedor tiene su tarjeta con:
1. **Client ID**, **Client Secret** (y **Tenant** en Microsoft). El secreto se
   guarda cifrado y nunca se vuelve a mostrar; la pantalla solo dice
   "Configurado" y los últimos 4 caracteres del Client ID. Si guardas con un
   campo en blanco se conserva el valor actual (útil para cambiar solo el tenant).
2. La **Redirect URI** de solo lectura (botón *Copiar*), que se registra en el
   proveedor. Se calcula con el dominio real de la app; no se escribe a mano.
3. **Conectar cuenta** (abre el inicio de sesión del proveedor, con permiso de
   solo lectura) y la lista de cuentas conectadas con **Desconectar**.

Cada guardado, conexión y desconexión queda en la bitácora (sin secretos ni tokens).

**Alternativa: `.env` del servidor (opcional).** Solo si no hay credenciales
guardadas en la app (lo guardado en la app tiene prioridad). Ver
`deploy/vps/env.produccion.example`:

```dotenv
MICROSOFT_CLIENT_ID=…       MICROSOFT_CLIENT_SECRET=…       MICROSOFT_TENANT=common
GOOGLE_CLIENT_ID=…          GOOGLE_CLIENT_SECRET=…
DROPBOX_CLIENT_ID=…         DROPBOX_CLIENT_SECRET=…
```
Las variables `*_REDIRECT_URI` ya no se usan. Después: `php artisan config:cache`.

## Rotación y revocación
- Antes de que venza un secret: crear uno nuevo, pegarlo en *Conexiones de nube*
  (Client Secret → Guardar) y recién entonces borrar el viejo en el proveedor.
- Si un secret se expuso (chat, correo, log, git): **rotarlo de inmediato**.
- Revocar acceso de una cuenta: desde el dashboard (*Conexiones de nube → Desconectar*)
  y, en el proveedor, quitar la app de las aplicaciones autorizadas de esa cuenta.
