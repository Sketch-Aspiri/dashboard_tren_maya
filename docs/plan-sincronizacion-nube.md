# Plan: Sincronización unificada Dashboard ⇄ Nube + despliegue en VPS Hostinger

## Contexto

**Qué hay hoy.** El dashboard tiene dos mecanismos que no se hablan entre sí:

1. **Importar Excel → tablas.** Se arrastra un archivo, `ImportacionService` / `LibroImportacionService`
   detectan encabezados y tipos, se muestra una vista previa, se aplica y se crean tablas y columnas
   (una tabla por hoja). El mapeo **no se guarda**: en cada importación se vuelve a adivinar,
   incluida la columna llave.
2. **Vincular un documento a un link público** (`EnlaceNubeResolver` + `NubeSyncService`). Funciona
   un documento a la vez, sin OAuth, descargando el archivo completo y comparando sha256. Las tablas
   se recargan en modo "actualizar" solo si se encuentra una llave. No hay carpetas, y los links
   institucionales de Microsoft fallan con **401**.

Además, en producción no está configurado el cron de `schedule:run` ni hay worker de cola: todo corre
dentro de la petición web.

**Objetivo.** Una sola función, **"Vincular desde la nube"**, que mantenga el dashboard siempre igual
a la nube (OneDrive/SharePoint, Google Drive, Dropbox). Funciona igual para un archivo suelto o para
una carpeta con subcarpetas, y se comporta según el tipo de sección:

| Tipo de sección | Qué hace la nube |
|---|---|
| **Tabla desde Excel** (p. ej. Rol de vacaciones, Directorio telefónico) | Cada hoja del Excel es una tabla espejo. Si el Excel cambia, la tabla cambia |
| **Solo documentos** (p. ej. "Drive …", Expedientes, Manuales) | La carpeta completa se refleja como documentos, con un navegador de carpetas |
| **Mixta** | En una carpeta, los Excel/CSV alimentan tablas y el resto (PDF, Word, imágenes) son documentos |

Todo esto en el **VPS de Hostinger con dominio público**, migrando los datos del servidor local.

### Decisiones tomadas contigo
- **Cuentas:** OAuth. Solo el administrador conecta cuentas (Microsoft personal e institucional, Google, Dropbox). Las apps de cada proveedor las registra TI.
- **La nube manda:** las tablas vinculadas son **de solo lectura** en el dashboard. Las filas que se borran del Excel se quitan de la tabla.
- **Documentos borrados en la nube:** se archivan (soft delete, restaurables).
- **Documentos modificados:** se guarda solo la última versión; el historial queda en la bitácora.
- **Subcarpetas:** se incluyen todas y se muestran con un **navegador de carpetas** dentro de Documentos. No se crean subsecciones.
- **Primer Excel:** se **confirma el mapeo una vez** con la vista previa actual. Después todo es automático.
- **Frecuencia:** cada 15 min, más un botón "Sincronizar ahora". Máximo 100 MB por archivo.
- **Despliegue:** dominio público (se actualizan AGENTS.md y las reglas) y migración de los datos actuales al VPS.

---

## 1. Modelo unificado: el "Origen de nube"

Un **origen** es cualquier cosa vinculada a una sección: un archivo o una carpeta, con cuenta o con
link público. Reemplaza las columnas `origen_*` que hoy están por documento.

### Tablas nuevas o modificadas (migraciones con `down()` y FK explícitas)

| Tabla | Campos clave |
|---|---|
| **`nube_cuentas`** | `proveedor` (enum `ProveedorNube`), `cuenta_email`, `cuenta_nombre`, `tenant_id`, `access_token` / `refresh_token` (cast `encrypted`, `$hidden`), `expira_en`, `scopes`, `estado` (activa \| requiere_reconexion), `ultimo_error`, `conectada_por`, soft deletes. Unique (`proveedor`, `cuenta_email`) |
| **`seccion_origenes`** | `seccion_id` (FK cascade), `nube_cuenta_id` (FK restrict, **nullable** = link público heredado), `tipo` (archivo \| carpeta, se **detecta** solo), `proveedor`, `enlace_original` (`$hidden`), `remoto_drive_id`, `remoto_item_id`, `nombre`, `incluir_subcarpetas`, `alimenta_tablas` (por defecto `!seccion.solo_documentos`), `estado` (pendiente_mapeo \| activo \| pausado \| error \| requiere_reconexion \| origen_eliminado), `sincronizado_en`, `sincronizacion_error`, `resumen` JSON (totales, omitidos y su motivo), `creado_por`, soft deletes |
| **`seccion_mapeos`** (la pieza nueva para tablas automáticas) | `seccion_origen_id`, `seccion_archivo_id`, `hoja` (nombre de hoja, o `CSV`), `seccion_tabla_id`, `fila_encabezado`, `columnas` JSON (`[{encabezado_normalizado, clave}]`), `llave_clave` (nullable), `confirmado_por`, `confirmado_en`. Unique (`seccion_archivo_id`, `hoja`) |
| `seccion_archivos` + | `seccion_origen_id` (FK null on delete), `remoto_id`, `remoto_ruta` (ruta relativa, p. ej. `Extintores/2026/Est X.pdf`), `remoto_version`, `remoto_modificado_en`, `contenido_hash`. Unique (`seccion_origen_id`, `remoto_id`) |
| `seccion_tablas` + | `seccion_origen_id` nullable. Si no es nulo, la tabla es **espejo (solo lectura)** |

**Migración de lo existente:** cada documento que hoy tiene `origen_url` se convierte en un
`seccion_origen` de tipo archivo con `nube_cuenta_id = null`, y se copian `origen_hash` y el estado.
Las columnas `origen_*` viejas se eliminan en una migración posterior, cuando se haya verificado en producción.

### Detección de cambios (igual en todos los proveedores)
El job lista **solo metadatos** del origen (recursivo y paginado) y compara cada archivo con
`seccion_archivos` usando `remoto_id` y el token de versión del proveedor:

| Proveedor | Token de contenido | Descarga |
|---|---|---|
| Microsoft Graph | `cTag` | `/drives/{d}/items/{id}/content` |
| Google Drive | `md5Checksum` (en Docs nativos, `version`) | `alt=media` / `export` a docx/xlsx/pptx |
| Dropbox | `content_hash` | `files/download` / `sharing/get_shared_link_file` |
| Link público (heredado) | sha256 después de descargar (como hoy) | URL resuelta por `EnlaceNubeResolver` |

Qué hace con cada caso:
- **Archivo nuevo:** lo crea.
- **Token distinto:** lo reemplaza.
- **Mismo token pero otro nombre o ruta:** solo renombra, sin descargar.
- **Ya no aparece:** lo archiva.
- **Reaparece:** lo restaura.

---

## 2. Pipeline de sincronización (un job por origen)

```
SincronizarOrigenJob (cola "nube", ShouldBeUnique por origen)
 1. ProveedorNube::listar(origen)             → ArchivoRemoto[]  (si es archivo: lista de 1)
 2. CalculadorDiferencias                     → altas / cambios / renombres / bajas / restauraciones
 3. por cada alta o cambio:
      descargar a un temporal → validar (reglasDeContenido) → guardar (ArchivoService) → extraer texto
      si origen.alimenta_tablas y es xlsx/xls/csv → TablasEspejoService::sincronizar(archivo)
 4. bajas → archivar documento (las tablas que alimentaba se marcan "origen eliminado", no se borran)
 5. actualizar origen.resumen / estado, bitácora
```

### Tablas automáticas (`TablasEspejoService`)
- **Primera vez** (origen en `pendiente_mapeo`): se crea una `SeccionImportacion` en
  `vista_previa`. Se reutilizan las vistas `secciones.importacion` / `importacion-libro` con un
  aviso: "Este mapeo se usará para todas las sincronizaciones". Al aplicar, se guardan
  `seccion_mapeos` (fila de encabezado, encabezado → clave, llave elegida), las tablas se marcan
  como espejo y el origen pasa a `activo`.
- **Siguientes cambios:** se aplica el mapeo guardado en un **modo nuevo,
  `ModoImportacion::Espejo`**, que se agrega a `PlanificadorImportacion::comparar` reutilizando la
  lógica de *actualizar* y *reemplazar*:
  - **Con llave:** actualiza las filas que coinciden, agrega las nuevas y archiva las que ya no vienen.
  - **Sin llave:** reemplaza todo, solo si cambió el `contenido_hash` de esa hoja.
- **Columna nueva en el Excel:** se crea la columna con el tipo inferido (`NormalizadorValores::inferirTipo`) y se agrega al mapeo.
- **Columna eliminada:** la columna se conserva y queda vacía para las filas nuevas.
- **Hoja nueva, o Excel nuevo dentro de una carpeta ya mapeada:** la tabla se crea **automáticamente**
  con los valores sugeridos (`filaEncabezadoSugerida`, `llaveSugerida`) y aparece el aviso
  "Tabla nueva creada automáticamente: revisar mapeo". El admin puede ajustar el mapeo desde "Editar mapeo".
- **Nombres de tabla:** si el origen es un archivo, se usa el nombre de la hoja (como hoy). Si el
  origen es una carpeta, se usa `«Archivo» · «Hoja»` para que no choquen.
- **Protección contra un Excel corrupto o a medio subir:** si una hoja llega vacía, o si en modo
  espejo se archivaría más del 50 % de las filas (configurable), **no se aplica**. El origen queda
  con el aviso "Cambio masivo, confirmar", con botones *Aplicar* / *Ignorar*.
- Cada aplicación queda como `SeccionImportacion` con `origen = nube` (resumen y cambios), para auditoría.
  En espejo no se ofrece "Deshacer", porque la siguiente sincronización lo volvería a aplicar.

### Solo lectura en tablas espejo
- Policies de filas y columnas (`SeccionPolicy` y los checks de `SeccionFila` / `SeccionColumna`):
  `create`/`update`/`delete` se deniegan si `tabla.seccion_origen_id` no es nulo.
- En las vistas se ocultan editar/borrar/agregar, la zona de arrastre y la importación manual en
  esas tablas, y se muestra la insignia "Sincronizada con OneDrive · hace 5 min". **Exportar sigue
  funcionando.** Los documentos espejo tampoco se pueden borrar ni re-vincular a mano.

### Reutilización (sin duplicar lógica)
- **`ExtractorDocumentos::tablas()`**, `PreparadorTabla`, `NormalizadorValores`: se usan tal cual.
- **`ImportacionService::propuesta()` / `LibroImportacionService::propuestas()`**: se les agrega un
  parámetro opcional `?SeccionMapeo` que fija la fila de encabezado, el mapeo y la llave, en lugar de adivinarlos.
- **`PlanificadorImportacion`**: se agrega el caso `Espejo`. La aplicación (transacción, chunks de 500, `cambios`) es la misma.
- **`NubeSyncService`**: se divide. `validarContenido` / `reemplazar` / `descargar` pasan a
  `DescargadorNube` y `AplicadorContenidoNube`. El resto queda reemplazado por el pipeline del job.
- **`ArchivoService`**: se agrega `guardarDesdeRuta(Seccion, rutaTemporal, nombre, User, ?SeccionOrigen)`.
- **Comando `secciones:sincronizar-nube`**: pasa a despachar `SincronizarOrigenJob` para todos los orígenes activos.

---

## 3. Proveedores y cuentas

```
app/Services/Nube/
  Contracts/ProveedorNube.php   inspeccionar(enlace, cuenta) → OrigenRemoto{tipo, ids, nombre}
                                listar(origen) → ArchivoRemoto[]; descargar(archivo, destino)
  Proveedores/MicrosoftGraph.php  GET /shares/u!{b64}/driveItem CON token (personal y Business),
                                  children recursivo con $select; descarga con redirect manual
  Proveedores/GoogleDrive.php     files.list q="'{id}' in parents and trashed=false"; export de nativos
  Proveedores/Dropbox.php         list_folder (recursive, shared_link) + continue
  Proveedores/EnlacePublico.php   envuelve EnlaceNubeResolver (solo tipo archivo, sin cuenta)
  ClienteNube.php                 Http con Bearer: refresca ante 401, respeta 429 Retry-After,
                                  valida el host de la redirección contra hosts_permitidos y NO reenvía el Bearer
  CuentaNubeService.php           conectar / refrescar / desconectar / marcar requiere_reconexion
```

- **OAuth:** `laravel/socialite` + `socialiteproviders/microsoft` (tenant `common`) +
  `socialiteproviders/dropbox`, con `refreshToken()` de Socialite. Para las APIs se usa el `Http`
  facade (sin SDKs pesados).
- **Scopes, todos de solo lectura:**
  - Microsoft: `offline_access User.Read Files.Read.All Sites.Read.All`
  - Google: `drive.readonly` (con `access_type=offline` y `prompt=consent`)
  - Dropbox: `files.metadata.read files.content.read sharing.read` (con `token_access_type=offline`)
- **Arreglo del 401:** si hay una cuenta Microsoft conectada, un link de archivo suelto se resuelve
  con Graph y ese token, no con el endpoint anónimo.
- **Hueco del zip de Dropbox:** se cierra porque `inspeccionar()` detecta si es carpeta, y porque los
  `.zip` no se aceptan como documento espejo.
- **Archivos omitidos** (aparecen en `resumen`, con el motivo):
  - extensión no permitida
  - más de 100 MB
  - accesos directos de Google
  - zip
  - y se aplican los límites `secciones.nube.max_archivos_por_origen` (2000) y `max_profundidad` (10)
- **Antes de descargar:** se verifica que el disco tenga libre al menos `secciones.nube.reserva_disco_gb` (p. ej. 10 GB).

---

## 4. Rutas, controladores y UI

**Rutas** (`routes/web.php`, grupo auth + verified + two-factor.verified + `scopeBindings`):
- **Cuentas (solo Administrador, `NubeCuentaPolicy`, throttle `nube-oauth`):**
  `GET /nube/cuentas`, `GET /nube/{proveedor}/conectar`, `GET /nube/{proveedor}/callback`, `DELETE /nube/cuentas/{cuenta}`
- **Orígenes (`SeccionPolicy::gestionarDatos`, throttle `secciones-write`):**
  - `POST /secciones/{seccion}/origenes/inspeccionar`: responde JSON con lo que se va a vincular
  - `POST /secciones/{seccion}/origenes`: vincular
  - `POST …/{origen}/sincronizar`: despacha el job y responde 202
  - `PUT …/{origen}`: pausar, reanudar o cambiar `alimenta_tablas`
  - `DELETE …/{origen}`: desvincular, conservando los datos como normales o archivándolos
  - `GET/POST …/{origen}/mapeo`: editar el mapeo y aplicar o ignorar un "cambio masivo"
- **Controladores** (delgados): `NubeCuentaController`, `SeccionOrigenController`, `SeccionMapeoController`.
- **Requests:** `VincularOrigenRequest`, que valida la cuenta activa, que el link sea https y que
  `inspeccionar()` responda bien. Los JSON usan el envelope de `api-conventions.md`.
- **Compatibilidad:** las rutas `archivos.nube.*` actuales (vincular un documento existente)
  siguen funcionando, pero ahora crean un origen de tipo archivo que "adopta" ese documento.

**UI** (Blade + Alpine, reutiliza el popover y la hoja móvil de `documentos.blade.php`):
- **Configuración → Conexiones de nube:** tarjetas por proveedor con Conectar / Reconectar / Desconectar.
- **Sección → botón "Vincular desde la nube":**
  1. Elegir una cuenta, o "link público (solo archivos)".
  2. Pegar el link.
  3. Vista de **inspección**: "Carpeta *Extintores*: 128 PDF, 3 Excel, 2 omitidos. Los Excel crearán tablas".
  4. Confirmar.
  5. Si hay Excel, se abre el mapeo una única vez.
- **Panel "Orígenes de la sección":** estado, "revisado hace…", totales, omitidos, errores, Sincronizar ahora / Pausar / Desvincular / Editar mapeo.
- **Navegador de carpetas en Documentos:**
  - Migas de pan armadas desde `remoto_ruta`.
  - Parámetro `?carpeta=`, validado en `ListarSeccionRequest`.
  - La búsqueda de texto completo sigue cubriendo todas las carpetas.
- **Página "Estado de sincronización"** (admin): todos los orígenes y cuentas, y los errores. Hay un
  banner global si alguna cuenta está en `requiere_reconexion` o algún origen espera un mapeo.

**Bitácora:**
- Se agregan: `cuenta_nube_conectada`, `cuenta_nube_desconectada`, `origen_vinculado`,
  `origen_desvinculado`, `mapeo_confirmado`, `documento_agregado_nube`,
  `documento_archivado_nube`, `documento_restaurado_nube`, `tabla_espejo_actualizada`, `cambio_masivo_retenido`.
- Se mantienen: `documento_sincronizado` y `sincronizacion_fallida`.

---

## 5. Plan del VPS Hostinger (KVM 2 · Ubuntu 24.04 · CloudPanel)

Se agrega una carpeta nueva, **`deploy/vps/`**, con `LEEME.md`, `deploy.sh`,
`supervisor-dashboard.conf`, `nginx-seguridad.conf`, `cron.txt`, `migrar-desde-local.md` y
`verificar-migracion.php`. También se actualiza `docs/despliegue-produccion.md`.

**V1. Provisión**
- Imagen de Ubuntu 24.04 con CloudPanel.
- Sitio PHP 8.3 con la plantilla de Laravel.
- Extensiones: `zip gd intl mbstring xml bcmath mysql curl fileinfo`.
- PHP-FPM: `upload_max_filesize=100M`, `post_max_size=110M`, `memory_limit=512M`.
- PHP CLI: `memory_limit=1G`, para los Excel grandes que procesa el worker.
- MySQL 8 con su base y usuario, `bind-address=127.0.0.1`.
- `libreoffice-writer` + `fonts-noto` (para los oficios de asistencia).

**V2. Red y seguridad (dominio público)**
- DNS: registro A del subdominio a la IP del VPS, y Let's Encrypt desde CloudPanel.
- Firewall de Hostinger + `ufw`:
  - **públicos solo 80/443**
  - SSH (22) y la interfaz de CloudPanel (8443) **solo por Tailscale**
- SSH: solo con llave, sin login de root, `fail2ban` (sshd + nginx), `unattended-upgrades`.
- Headers de seguridad en Nginx: HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy.
  `client_max_body_size 110M`.
- **Obligatorio antes de abrir al público:**
  - `TWO_FACTOR_ENABLED=true`
  - cuentas individuales en lugar de la cuenta compartida
  - rate limiting de login verificado
  - `APP_DEBUG=false`
  - `SESSION_SECURE_COOKIE=true`
- Actualizar AGENTS.md (líneas 34-35, 48-49, 66-78, 200), `.claude/rules/server-conventions.md` y
  `.cursor/rules/server-conventions.mdc` con la regla nueva: "HTTPS público + 2FA + rate limiting;
  SSH, panel y MySQL nunca públicos".

**V3. Aplicación**
- Deploy key de GitHub de solo lectura, `git clone` en el usuario del sitio.
- `.env` de producción, sin commitear. Variables nuevas: `QUEUE_CONNECTION=database`,
  `SECCIONES_SYNC_MINUTOS=15`, `LOG_CHANNEL=daily`, `MICROSOFT_*` / `GOOGLE_*` / `DROPBOX_*`.
- `deploy.sh`:
  1. `php artisan down`
  2. `git pull`
  3. `composer install --no-dev -o`
  4. `npm ci && npm run build` (con el Node de CloudPanel; o compilar en local y subir `public/build`)
  5. `migrate --force`
  6. `config:cache`, `route:cache`, `view:cache`
  7. `queue:restart`
  8. `php artisan up`

**V4. Procesos en segundo plano**
- Cron del usuario del sitio: `* * * * * cd ~/htdocs/<sitio> && php artisan schedule:run >> /dev/null 2>&1`
- Supervisor: `queue:work database --queue=nube,default --tries=3 --timeout=900 --max-time=3600 --memory=900`,
  con `numprocs=1` (2 vCPU; los Excel grandes no deben procesarse en paralelo).
- `config/queue.php`: conexión `database-nube` con `retry_after=960`, para que sea mayor que el timeout.
  Si no, el job se duplica.

**V5. Migración de datos desde el servidor local**
1. En el servidor local: `php artisan down`. Revisar qué motor usa la base: el kit de estación usa
   MySQL 8 y en desarrollo puede ser SQLite. Ver tamaños de la base y de `storage/app/private`.
2. Hacer `mysqldump --single-transaction --routines --hex-blob` y comprimir `storage/app/private`.
   Si es SQLite, exportar con un script de conversión, no copiar el `.sqlite`.
3. Transferir con `scp` por Tailscale, importar en MySQL del VPS y copiar los archivos con los permisos del usuario del sitio.
4. `php artisan migrate --force`, para aplicar las migraciones nuevas, incluida la que convierte los `origen_*` en orígenes.
5. `verificar-migracion.php`: compara los conteos por tabla contra los del origen y comprueba que
   exista cada `seccion_archivos.ruta` en el disco.
6. Usuarios: se conservan, pero se fuerza el cambio de contraseña y el alta de 2FA.
7. Suposición: hay **un solo servidor local**. Si hubiera varias estaciones con bases propias
   (`deploy/estacion-windows/estaciones.csv`), unirlas sería un trabajo aparte.

**V6. Respaldos y monitoreo**
- Respaldo diario de la base desde CloudPanel, más `rsync`/`rclone` de `storage/app/private` a un destino externo.
  Snapshot semanal de Hostinger. **Prueba de restauración** mensual.
- Monitoreo:
  - `/up` (health check de Laravel 11) en un servicio de uptime
  - alerta de disco > 80 %, porque los documentos espejo crecen
  - revisión de `failed_jobs`
  - `supervisorctl status`

---

## 5b. Protección de la base de datos de producción

Estos cambios **sí tocan la BD**: crean tablas nuevas, agregan columnas y convierten los vínculos
`origen_*`. Por eso se siguen estas reglas:

1. **La BD actual no se modifica directamente.** La migración al VPS trabaja sobre una **copia**
   (dump). El servidor local queda intacto como respaldo y plan de regreso hasta que el VPS esté
   validado.
2. **Primero F0, luego las fases nuevas.** El VPS arranca con el código actual, sin cambios de
   esquema. Las fases F1-F7 se aplican después y cada una se prueba primero en **staging**: otra
   base en el mismo VPS, restaurada desde el respaldo del día.
3. **Migraciones solo aditivas.** Crean tablas y agregan columnas nullable. No hay `drop`, `rename`
   ni cambio de tipo en columnas que ya existen.
   - La conversión `origen_*` → `seccion_origenes` **copia** los datos y deja las columnas viejas.
   - Esas columnas se borran en una migración aparte, varias semanas después y solo con confirmación explícita.
4. **Respaldo automático antes de cada `migrate`.** `deploy.sh` hace `mysqldump` + tar de
   `storage/app/private` y aborta si el respaldo falla. Cada migración tiene `down()` probado.
5. **Los datos existentes no cambian solos.** El modo espejo y la protección de solo lectura **solo**
   aplican a tablas que el admin vincule a la nube. Las tablas y documentos actuales siguen
   exactamente igual hasta que alguien los vincule. Al vincular, el mapeo se confirma una vez y se
   activa la protección contra cambios masivos.
6. Cada aplicación de espejo guarda `cambios` en `SeccionImportacion`, igual que hoy, así que se
   puede auditar e incluso revertir con un comando de soporte si hiciera falta.

## 6. Fases (cada una: TDD → code-writter → code-reviewer → security-auditor)

| # | Fase | Resultado |
|---|---|---|
| F0 | **VPS base y migración** (V1-V6) + 2FA + actualización de reglas | El dashboard actual corre en el VPS con dominio público, cron y worker |
| F1 | **Refactor unificado sin cambiar el comportamiento**: `seccion_origenes`, migrar los `origen_*`, `ProveedorNube` + `EnlacePublico`, job + cola + scheduler, dividir `NubeSyncService` | Lo que ya funciona, ahora en segundo plano |
| F2 | **Cuentas OAuth + Microsoft Graph** (archivo y carpeta) | Se arregla el 401 y se pueden vincular carpetas de OneDrive/SharePoint |
| F3 | **Documentos espejo + navegador de carpetas** + altas, bajas y restauraciones | Secciones "solo documentos" sincronizadas |
| F4 | **Tablas automáticas**: `seccion_mapeos`, modo `Espejo`, tablas de solo lectura, confirmar una vez, protección contra cambios masivos | Secciones de Excel sincronizadas, también las mixtas |
| F5 | **Google Drive** (con exportación de nativos) | — |
| F6 | **Dropbox** | — |
| F7 | **Panel de estado + avisos** y guía para TI (`docs/nube-oauth.md`) | — |

**Guía para TI** (`docs/nube-oauth.md`):
- **Entra ID:** "cualquier directorio + cuentas personales", redirect `https://<dominio>/nube/microsoft/callback`.
  El admin de M365 probablemente tenga que dar consentimiento para `Files.Read.All` / `Sites.Read.All`.
  Anotar cuándo vence el secret.
- **Google:** la app debe estar en "Internal" (si hay Workspace) o publicada; en "Testing" el token
  vence a los 7 días. `drive.readonly` es un scope restringido y Google pide verificarlo.
- **Dropbox:** scopes de arriba, refresh offline.

## Archivos clave
- **Modificar:**
  - `app/Services/Secciones/{NubeSyncService, ArchivoService, ImportacionService, LibroImportacionService, PlanificadorImportacion, EnlaceNubeResolver}.php`
  - `app/Enums/ModoImportacion.php`
  - `app/Models/{Seccion, SeccionArchivo, SeccionTabla}.php`
  - `app/Policies/SeccionPolicy.php`
  - `app/Http/Requests/Secciones/ListarSeccionRequest.php`
  - `app/Console/Commands/SincronizarNubeCommand.php`
  - `routes/{web,console}.php`
  - `config/{secciones,services,queue}.php`
  - `.env.example`
  - `resources/views/secciones/{show, partials/documentos, importacion, importacion-libro}.blade.php`
  - `app/Providers/AppServiceProvider.php`
  - `AGENTS.md`, las reglas de servidor y `docs/despliegue-produccion.md`
- **Crear:**
  - `app/Services/Nube/*` y `app/Services/Secciones/{TablasEspejoService, CalculadorDiferencias, AplicadorContenidoNube, DescargadorNube}.php`
  - `app/Jobs/SincronizarOrigenJob.php`
  - los modelos `NubeCuenta`, `SeccionOrigen`, `SeccionMapeo` y sus migraciones
  - las Policies, Requests y controladores de arriba
  - las vistas `nube/cuentas`, `secciones/partials/{origenes, navegador-carpetas}` y `nube/estado`
  - `deploy/vps/*`

## Verificación
- **Feature tests** (`Http::fake`, `Storage::fake`, `Queue::fake`/sync, `RefreshDatabase`):
  - migrar los `origen_*` genera orígenes equivalentes, y los tests actuales de `SincronizacionNubeTest` siguen pasando
  - OAuth: los tokens quedan cifrados en la BD; si falla el refresh → `requiere_reconexion`; un 429 respeta Retry-After;
    una redirección a un host no permitido se rechaza; el Bearer no se envía al host de descarga
  - Carpeta: la primera sincronización crea N documentos con `remoto_ruta`; cambio → reemplaza;
    renombre → no descarga; baja → archiva + bitácora; reaparece → restaura; omitidos con motivo
  - Tablas:
    - el primer Excel queda en `pendiente_mapeo` y la vista previa guarda el mapeo
    - el Excel modificado aplica en modo espejo (actualiza, agrega y archiva filas)
    - una columna nueva se crea sola, una hoja nueva crea su tabla y deja un aviso
    - la protección contra cambios masivos retiene la aplicación
    - en una sección mixta, los Excel van a tablas y los PDF a documentos
  - Solo lectura: editar o borrar una fila o columna de una tabla espejo → 403; exportar funciona
  - Autorización: Estación → 403, invitado → redirect, `scopeBindings` entre secciones
- **Unit:** `CalculadorDiferencias` y el modo `Espejo` del planificador, con un `ProveedorNube` falso.
- `php artisan test`, `./vendor/bin/pint --test`, cobertura ≥80 % en lo nuevo (si PCOV no está instalado, se dice).
- **En el VPS (staging, antes de abrir el DNS):**
  1. `verificar-migracion.php` en verde.
  2. Conectar una cuenta Microsoft personal y una institucional.
  3. Vincular una carpeta de PDFs con subcarpetas a una sección "solo documentos" y un Excel de
     varias hojas a una sección de tablas.
  4. En la nube: editar una fila, agregar una hoja, borrar un PDF y renombrar otro.
  5. Correr `php artisan secciones:sincronizar-nube` o esperar 15 min, y comprobar los cambios en el
     dashboard y en la bitácora.
  6. `crontab -l`, `supervisorctl status`, `/up`, y revisar `failed_jobs` vacío.
