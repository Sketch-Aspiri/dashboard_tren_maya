# Ecosistema Zona Oriente (Dashboard + Tickets)

El Dashboard (`dashboard.zonaorientetm.tech`) y el sistema de Tickets
(`tickets.zonaorientetm.tech`) son dos aplicaciones Laravel independientes en
la misma VPS, con su propia BD, usuarios y permisos. Se presentan como **un
solo ecosistema**. Este archivo existe, igual, en los dos repositorios. Si
cambias algo de esta lista en uno, cámbialo también en el otro.

## Piezas compartidas (fase 1)

| Pieza | Dashboard | Tickets |
|---|---|---|
| Configuración | `config/ecosistema.php` (`sistema`, `urls.*`) | `config/ecosistema.php` |
| Selector de sistema | `components/ecosistema-selector` (barra superior y menú de celular) | `components/ecosistema-selector` (cabecera de la barra lateral) |
| Pie común | `components/ecosistema-pie` | `components/ecosistema-pie` |
| Tema claro/oscuro | `layouts/partials/tema.blade.php` + `resources/js/tema.js` | `public/js/tema.js` + `resources/js/theme.js` |
| Tipografía | Figtree (texto) y Poppins (títulos) con `@fontsource`, importadas en `resources/js/app.js` | Las mismas |
| Temayin | `app/Support/Temayin.php` + `components/temayin-asistente` | `lang/es/assistant.php` + `components/temayin-assistant` |

### Tema compartido
- La preferencia (`sistema`, `claro` u `oscuro`) se guarda en la cookie `tema` del dominio padre común (`.zonaorientetm.tech`), con `SameSite=Lax`, `Secure` y vigencia de un año. También se guarda en `localStorage`.
- La cookie manda: si cambias el tema en un sistema, el otro lo aplica al cargar.
- En `localhost` o en una IP la cookie queda solo en ese host.
- No es un dato sensible. Solo la lee JavaScript; el servidor no la usa.
- El script del `<head>` aplica la clase `dark` antes de pintar:
  - En Tickets es un archivo propio, por su CSP `script-src 'self'`.
  - En el Dashboard es un script en línea.
- Los colores oscuros son las variables `--o-*` del bloque "Modo oscuro" al final de `resources/css/app.css`. Usa los mismos valores en ambos sistemas.

### Tokens de diseño
- Marca:
  - `brand.mist #F2F7F5`
  - `brand.mint #70C2AD` (solo decorativo)
  - `brand.teal #1F7460` (enlaces y foco)
  - `brand.green #06534D` (acciones primarias)
  - `brand.green-dark #043D39`
- Modo oscuro:
  - Fondos: `--o-fondo #0b1513`, `--o-sup #12201d`, `--o-sup2 #192b27`, `--o-sup3 #213833`.
  - Textos: `--o-texto #e4eeeb`, `--o-texto2 #b6c7c2`, `--o-texto3 #8fa39d`.
  - Marca y enlaces: `--o-marca #86d6c1`, `--o-enlace #6fcab3`.
- El logotipo va sobre una placa clara en modo oscuro (clase `logo-placa`).

## Fases siguientes
Ver el plan aprobado:
- **Fase 2:** inicio de sesión único, con salto firmado de un solo uso por correo.
- **Fase 3:** pendientes de Tickets en el Dashboard y reporte de fallas con contexto.
- **Fase 4:** eventos del Dashboard que se vuelven actividades.
- **Fase 5:** avisos push por la PWA de Tickets.

Todas usan un "puente" interno firmado con HMAC entre los dos servidores.

## Inicio de sesión único (fase 2)
- **Clases:** Dashboard `App\Services\Ecosistema\{FirmaEcosistema,InicioUnicoService}` + `EcosistemaSesionController`; Tickets `App\Services\Ecosystem\{EcosystemSignature,SingleSignOnService}` + `Auth\EcosystemSessionController` (decisión 88).
- **Rutas iguales en ambos:**
  - `GET /ecosistema/sso?para=&estado=`: el otro sistema pregunta si aquí hay sesión.
  - `GET /ecosistema/entrar?codigo=|sin_sesion=1`: llega la respuesta.
  - `GET /ecosistema/salir?codigo=`: cierre único.
- **Código firmado:**
  - HMAC-SHA256 con `ECOSISTEMA_SSO_SECRET`, el mismo valor en los dos `.env`.
  - Carga `{v, iss, aud, act, email, mfa, state, nonce, exp}`.
  - Dura 60 s, es de un solo uso y va ligado a destino, acción y navegador (`state`).
- **Vinculación:** el correo solo no basta.
  - La primera vez que alguien llega de un sistema al otro, inicia sesión ahí una sola vez con su contraseña y su cuenta queda vinculada: `sso_vinculado_at` en el Dashboard, `sso_linked_at` en Tickets.
  - Es una vez por sentido y por cuenta, no por dispositivo.
  - Cambiar el correo rompe el vínculo.
- **2FA:** si el origen ya lo verificó y la cuenta destino lo tiene configurado, no se vuelve a pedir.
- **Si el otro sistema está caído o no hay secreto,** cada uno muestra su login normal (`?local=1` también lo fuerza).

## Puente interno y fase 3 (pendientes y reporte de fallas)
- **Puente:** llamadas de servidor a servidor bajo `/interno/*`, fuera del grupo `web` (sin sesión, cookies ni CSRF). El navegador nunca las usa.
  - **Clases:** Dashboard `App\Services\Ecosistema\{FirmaPuente,PuenteCliente}` + middleware `VerificarPuente`; Tickets `App\Services\Ecosystem\{BridgeSignature,BridgeClient}` + middleware `VerifyBridge` (decisión 89). Mismo formato en ambos: no cambiar una sin la otra.
  - **Firma:** `hex(hmac_sha256(secreto, MÉTODO \n /ruta \n consulta \n sistema \n fecha \n nonce \n sha256(cuerpo)))`. La consulta va ordenada por nombre y codificada RFC 3986.
  - **Encabezados:** `X-Puente-Sistema`, `X-Puente-Fecha` (unix, ±5 min), `X-Puente-Nonce` (un solo uso, en caché) y `X-Puente-Firma`.
  - **Un secreto por dirección, el mismo valor en los dos `.env`:** `ECOSISTEMA_PUENTE_SECRETO_DASHBOARD` (lo que el Dashboard pide a Tickets) y `ECOSISTEMA_PUENTE_SECRETO_TICKETS` (lo que Tickets pide al Dashboard). Sin secretos, `/interno/*` responde 404 y todo sigue como antes.
  - **Opcionales:** `ECOSISTEMA_PUENTE_IPS` (IPs permitidas, coma) y `ECOSISTEMA_PUENTE_TICKETS_URL` / `ECOSISTEMA_PUENTE_DASHBOARD_URL` (dirección interna del otro sistema).
  - **Cliente:** timeout de 3 s y 2 reintentos; si el otro sistema falla, devuelve nada y la pantalla sigue.
- **Mis pendientes en Tickets (en el Dashboard):**
  - Tickets: `GET /interno/pendientes?correo=`. Usa las mismas consultas de «Mis pendientes» (`pendingQuery`, alcance propio) y solo responde por cuentas activas, con rol y **vinculadas** (`sso_linked_at`); si no, `{linked: false}`.
  - Devuelve conteos (tickets, actividades, vencidos, en revisión), los 5 más urgentes (folio, título, estado, prioridad, vencimiento, URL relativa) y la URL de «Mis pendientes».
  - Dashboard: `TicketsPendientes` lo guarda 60 s por persona (también la falla) y la tarjeta `x-tickets-pendientes` se muestra en el dashboard principal y en el inicio de estación. Títulos siempre escapados; solo se aceptan rutas relativas de Tickets.
- **Reportar una falla con contexto:**
  - El enlace de Soporte de Temayin va a `GET /ecosistema/reportar?desde=<ruta>` del Dashboard (solo la ruta, sin consulta).
  - El Dashboard guarda 15 min el contexto (página, rol, estación, fecha y navegador; sin montos ni datos personales) bajo un id aleatorio y manda a `{tickets}/tickets/create?prefill=<id>`.
  - Tickets lo pide por `GET /interno/contextos/{id}?correo=` del Dashboard: se entrega una sola vez y solo al mismo correo. Prellena título y descripción como texto plano y preselecciona Soporte del sistema; la persona revisa y envía por el flujo normal.
  - Sin puente, el enlace abre el formulario de Soporte vacío, como antes.

## Endurecimiento tras la auditoría de seguridad (decisión 90)
- **El vínculo espera al 2FA.** Antes la cuenta se vinculaba al validar la contraseña, y desde ahí el 2FA del otro sistema se heredaba: quien tuviera la contraseña de la víctima (sin su código) podía vincularla. Ahora, si la cuenta tiene 2FA (o su rol lo exige), el vínculo queda pendiente en la sesión y se completa al pasar el reto o al configurar el 2FA (`completarVinculo` / `completeLink` también se llaman desde esos dos controladores).
- **Las dos cuentas deben estar vinculadas.** El código firmado lleva `lnk` (¿la cuenta de ORIGEN está vinculada?). El destino solo abre sesión sin contraseña si su cuenta Y `lnk` lo están; si no, pide la contraseña (como la primera vez). Sin `lnk`, vale `false`. Motivo: el correo del Dashboard se puede editar sin verificar, así que no basta con que la cuenta de destino esté vinculada.
  - Consecuencia: la primera vez hacen falta dos pasadas, una en cada sentido, escribiendo la contraseña de cada sistema una sola vez.
- **Dashboard:** cambiar el correo del perfil exige la contraseña actual (y rompe el vínculo); la tarjeta de pendientes no pregunta por una cuenta sin vincular.
- **Puente:** el cliente no sigue redirecciones (no reenvía las cabeceras de firma) y los patrones de validación usan `D`/`\z` para no aceptar un salto de línea final.
- **Datos:** `database/data/proveedores_fondo.json` (RFC de personas físicas) está en `.gitignore`: los repos son públicos.

### Lista previa al despliegue
- `CACHE_STORE=database` o `redis` en ambos (nunca `file` ni `array`: el nonce y el contexto de un solo uso dependen de `Cache::add`/`pull`), `SESSION_SECURE_COOKIE=true`, `SESSION_DOMAIN=null`.
- Tres secretos de 32+ caracteres, distintos entre sí y fuera de git: `ECOSISTEMA_SSO_SECRET`, `ECOSISTEMA_PUENTE_SECRETO_DASHBOARD` y `ECOSISTEMA_PUENTE_SECRETO_TICKETS`.
- Dashboard: `trustProxies` con las IPs del túnel o de Cloudflare, no `'*'` (con `'*'` la IP del cliente se puede falsear: el límite de intentos y `ECOSISTEMA_PUENTE_IPS` no protegen). Tickets: `TRUSTED_PROXIES` y `TRUSTED_HOSTS`.
- `ECOSISTEMA_PUENTE_IPS` y las URLs internas `ECOSISTEMA_PUENTE_*_URL` (localhost o vhost) para que el puente no salga a internet.
- `composer audit` en ambos repos.

## Fase 5: avisos al celular (PWA de Tickets)
- **Flujo:** un evento del Dashboard encola `EnviarAvisoTickets` (cola `default`, 4 intentos, espera 30 s / 2 min / 10 min). El trabajo hace `POST /interno/avisos` a Tickets por el puente; Tickets guarda el aviso en «Notificaciones» y lo manda por Web Push a los celulares de la persona. Si Tickets está caído el aviso se reintenta y la acción original nunca se frena (encolar va en `try/catch`).
- **Puente (POST):** la firma cubre el hash del cuerpo exacto. Dashboard `PuenteCliente::post` (sin reintentos propios y sin seguir redirecciones); Tickets `EcosystemNoticeController` + `EcosystemNoticeRequest` (solo acepta al Dashboard). Cada aviso lleva un `ref` (uuid): Tickets no avisa dos veces el mismo `ref` en 24 h aunque el Dashboard reintente.
- **Límites en Tickets:** hasta 50 correos, título ≤ 60, cuerpo ≤ 120 (sin saltos de línea: se ven en la pantalla bloqueada) y una URL del ecosistema (ruta de Tickets o URL de los sistemas de `config('ecosistema.urls')`, mismo origen exacto, sin usuario, fragmento ni `//`; se reconstruye y se vuelve a validar al abrir el aviso: `App\Support\EcosystemUrl`).
- **Destinatarios:** solo cuentas activas, con rol y **vinculadas** en ambos sistemas (el Dashboard elige entre sus cuentas vinculadas y Tickets vuelve a exigirlo). Tickets no revela cuáles correos existen: responde solo el conteo.
- **Eventos del Dashboard** (`App\Services\Ecosistema\AvisosEcosistema`; textos con estación, mes y acción, sin comentarios ni montos):
  - Solicitud de insumos **enviada** → Jefes de Zona.
  - Solicitud **observada** (devuelta) o **aprobada** → personas de esa estación (URL `insumos/estaciones/{id}`).
  - **Cotización por revisar** (cuando PASA a REVISAR, no en cada guardado) → Jefes de Zona, menos quien la capturó.
  - **Asistencia pendiente** → personas de cada estación sin asistencia capturada. Comando `asistencia:recordar-pendientes`, lunes a viernes a las 10:00 (America/Cancun; `ECOSISTEMA_AVISO_ASISTENCIA_HORA`), una vez por estación y día.
- **Service worker (Tickets, `VERSION` v2):** `notificationclick` abre URLs de este sitio y también `https://*.zonaorientetm.tech`. Una URL del Dashboard pasa por su pantalla de acceso: el inicio de sesión único (fase 2) entra sin contraseña si las cuentas están vinculadas y vuelve a la ruta pedida.
- **Activar el aviso:** la persona instala Tickets en su celular y pulsa «Activar notificaciones» (`/notifications`); en el Dashboard, «Mi perfil» trae un enlace a esa pantalla. Requiere las claves VAPID de Tickets y el worker de cola del Dashboard (`deploy/vps/supervisor-dashboard.conf` ya procesa la cola `default`).
- **Sin el puente configurado** no se encola nada y el comando de asistencia no hace nada.

## Fase 4: eventos del Dashboard que se vuelven actividades en Tickets
- **Flujo:** un evento del Dashboard encola `EnviarActividadTickets` (cola `default`, 4 intentos, espera 30 s / 2 min / 10 min). El trabajo hace `POST /interno/actividades` a Tickets; Tickets crea la actividad y responde `{folio, url, creada}`; el Dashboard guarda el vínculo en `ecosistema_vinculos` (modelo, id, folio, enlace) y lo muestra como «Seguimiento en Tickets: ACT-2026-0012» en la solicitud y en la factura de origen. Encolar va en `try/catch`: la acción original nunca se frena.
- **Eventos** (`App\Services\Ecosistema\ActividadesEcosistema`; solo estación, mes, folio, acción y enlace: nunca montos, RFC ni datos personales):
  - `insumos_observada`: la Zona devuelve la solicitud con observaciones → actividad para la estación (lleva el comentario de Zona recortado a 400 caracteres).
  - `insumos_devuelta`: Coordinación devuelve la solicitud con ajustes → actividad para la estación (una por estación devuelta).
  - `factura_cancelada`: una factura PASA a cancelada en el SAT (no en cada consulta) → actividad para Recursos materiales.
  - `asistencia_pendiente`: a la hora límite (`asistencia:abrir-actividades`, lunes a viernes 12:00 Cancún, `ECOSISTEMA_ACT_ASISTENCIA_HORA`) la estación sigue sin capturar → actividad para la estación; una vez por estación y día.
  - Cada hecho lleva un `ref` único (p. ej. `dashboard:solicitud:12:observada:1790000000`): Tickets lo guarda en `activities.external_ref` (único), así que reenviar el evento devuelve la misma actividad (`creada: false`).
- **Equipo destino: lo decide Tickets, no el Dashboard** (`config/ecosistema.php` de Tickets > `actividades`). **Sin equipo configurado para un evento no se crea nada** (Tickets responde 422 `sin_equipo` y el Dashboard no reintenta):
  - `ECOSISTEMA_ACT_EQUIPO_INSUMOS_OBSERVADA`, `..._INSUMOS_DEVUELTA`, `..._FACTURA_CANCELADA`, `..._ASISTENCIA`: id o nombre EXACTO del equipo (un nombre repetido en varias coordinaciones no se adivina).
  - `ECOSISTEMA_ACT_ESTACIONES`: JSON `{"codigo de estación del Dashboard": equipo}` para que los eventos «de estación» vayan al equipo de cada una; si una estación no está, usan el equipo general del evento.
  - `php artisan ecosystem:teams` (Tickets) lista los equipos con su id, coordinación y coordinador, y qué eventos siguen sin configurar.
  - En el Dashboard, `ECOSISTEMA_ACT_EVENTOS` elige qué eventos se mandan (por omisión los cuatro).
- **Quién la recibe:** la actividad se asigna como responsable al **coordinador del equipo** (si el equipo no tiene un coordinador activo: 422 `sin_responsable`, no se crea), que decide quién la atiende; le llega el aviso de asignación (campana y Web Push). Plazo y prioridad por evento en la misma configuración.
- **Creador:** el usuario de sistema «Dashboard (automático)» (`dashboard-automatico@sistema.invalid`), que Tickets crea la primera vez: inactivo, sin rol y con una contraseña aleatoria que nadie conoce, así que no puede iniciar sesión.
- **Datos no confiables:** Tickets limita títulos (120) y descripciones (1000), rechaza caracteres de control, valida la URL de origen (`EcosystemUrl`) y lo muestra siempre escapado. El Dashboard solo guarda el folio y la ruta `/activities/{id}` de Tickets si tienen el formato esperado; nunca otro dominio.

## Endurecimiento tras la auditoría de las fases 4 y 5 (decisión 93)
- **Cuenta de sistema «Dashboard (automático)»:**
  - El registro público rechaza cualquier correo `.invalid` (dominio reservado) y, por tanto, el de la cuenta de sistema; antes de la primera actividad nadie puede reservarla.
  - Tickets solo usa esa cuenta si es inactiva, sin rol y sin equipos; si no, no crea nada (422 `usuario_sistema_invalido`) y deja un `Log::alert`. Si ya había alguien registrado con ese correo, hay que rechazar esa cuenta en «Usuarios».
  - La cuenta no se lista ni se gestiona desde la interfaz: `User::scopeManageableBy` la excluye (404 en ver, aprobar, activar y editar) y `UserService` lo rechaza también por si otro camino llegara ahí.
- **Service worker (`VERSION` v3):** el aviso solo abre este sitio o un host de la lista exacta `ECOSYSTEM_HOSTS` (`tickets.` y `dashboard.zonaorientetm.tech`), por `https` y sin puerto; ya no vale cualquier `*.zonaorientetm.tech`. Si cambian los dominios hay que cambiar la lista (y `VERSION`) junto con `config('ecosistema.urls')`.
- **URL de un aviso o actividad:** además del origen, solo rutas de consulta (`tickets`, `activities`, `notifications`, `chat`, `tracking`, `dashboard`, `profile`, `insumos`, `asistencia`, `secciones`, `agenda` o la raíz). Nunca `/ecosistema/...`, `/logout`, `/interno`, `/storage`, `..` ni rutas con `export`, `descargar`, `download`, `reporte`, `salir` o `preview`: un simple GET no puede cerrar la sesión ni descargar un archivo.
- **Textos:** títulos, cuerpos y descripciones rechazan caracteres de formato Unicode (dirección del texto, ancho cero, separadores de línea); el Dashboard los quita antes de enviar.
- **Actividades:**
  - `activities.external_ref` guarda `evento:ref` (el mismo `ref` con otro evento es otro hecho).
  - Si el `ref` ya tuvo una actividad que se eliminó, Tickets responde `{creada: false, eliminada: true}` sin folio y el Dashboard no guarda ningún vínculo.
  - Tope de actividades NUEVAS por evento y hora (`ECOSISTEMA_ACT_LIMITE_HORA`, 60): pasado el tope, 429 y el Dashboard reintenta más tarde; lo ya creado se sigue devolviendo.
- **Avisos:** si el envío falla después de reservar el `ref`, el `ref` se libera para que el reintento del Dashboard sí avise.
- **Dashboard:** `json_encode` ya no puede lanzar una excepción sin controlar desde un trabajo; «Seguimiento en Tickets» solo imprime como enlace una actividad de Tickets (si no, solo el folio); `queue:prune-failed` limpia cada día los trabajos fallidos de más de 7 días.
- **Decisión pendiente (no es código):** el comentario de Zona (hasta 400 caracteres) y la serie y folio de la factura llegan al coordinador del equipo destino. Confirma con el jefe de zona que ese equipo es parte legítima del proceso.
- **Diferido a propósito:** `Cache::add` por estación y día en los comandos de asistencia (si encolar falla, ese día no se repite; «a lo más una vez»), `Relation::enforceMorphMap` en el Dashboard (rompería filas existentes) y `composer audit` (no hay `composer` en la PC de desarrollo; correrlo en el VPS o en CI).

### Lista previa al despliegue (fases 4 y 5)
- Migrar AMBOS sistemas (`activities.external_ref`, `ecosistema_vinculos`) y comprobar el índice único en MySQL.
- Dashboard: worker de cola bajo Supervisor (`deploy/vps/supervisor-dashboard.conf` procesa `default`) y `schedule:run` activo (asistencia 10:00 y 12:00, America/Cancun). Tickets: worker de cola y claves VAPID.
- Tickets: `ECOSISTEMA_ACT_EQUIPO_*` y `ECOSISTEMA_ACT_ESTACIONES` (con `php artisan ecosystem:teams`); cada equipo con un coordinador activo.
- `ECOSISTEMA_PUENTE_IPS` con la IP del otro servidor; tras cambiar secretos o `.env`, `php artisan config:cache`.
- Probar un aviso de extremo a extremo en un celular real y comprobar que el service worker nuevo se actualiza.
