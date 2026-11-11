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
