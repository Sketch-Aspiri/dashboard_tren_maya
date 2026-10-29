# Dashboard Jefe de Zona — Tren Maya

Instrucciones canónicas para agentes (Cursor lee `AGENTS.md`; `CLAUDE.md`
es un enlace simbólico a este archivo para compatibilidad con Claude Code).

## Descripción del proyecto

Sistema web (dashboard) de consulta para el Jefe de Zona del Tren Maya. Incluye
panel de indicadores (KPIs), gráficas interactivas, buscador y un módulo CRUD
para la administración de la información. Es la primera versión del sistema:
diseñado para un solo usuario (el Jefe de Zona), pero preparado desde el
diseño para escalar a más usuarios en el futuro.

**Estatus actual:** preparado para producción sobre MySQL. Como el modelo de
datos de cada concepto no se entregó, el propio usuario define y llena la
información desde la pantalla (secciones dinámicas, ver abajo), capturando a
mano o arrastrando documentos.

## Stack tecnológico

| Componente | Tecnología |
|---|---|
| Backend | Laravel 11 (PHP 8.3) |
| Frontend | Blade + Tailwind CSS + Alpine.js |
| Gráficas | Chart.js / ApexCharts |
| Base de datos | MySQL 8 |
| Autenticación | Laravel Breeze/Fortify + `pragmarx/google2fa-laravel` (2FA) |
| Roles y permisos | `spatie/laravel-permission` |
| Auditoría | `spatie/laravel-activitylog` |
| Control de versiones | Git (repositorio privado) |
| Servidor web | Nginx (vía CloudPanel) |
| Sistema operativo (prod) | Ubuntu 24.04 LTS |
| Panel de administración del servidor | CloudPanel (gratuito) |
| Hosting | VPS Hostinger, plan KVM 2 (2 vCPU / 8 GB RAM / NVMe), datacenter en Estados Unidos |
| Acceso remoto seguro | VPN (Tailscale) — el sistema nunca se expone abiertamente a internet |
| Certificado SSL | Let's Encrypt (renovación automática) |

## Arquitectura

Arquitectura de tres capas, alojada en un VPS propio:

- **Presentación:** interfaz web responsiva (Blade + Tailwind + Alpine.js),
  usada desde computadora o celular.
- **Aplicación:** lógica de negocio en Laravel (auth, CRUD, gráficas,
  buscador, reportes).
- **Datos:** MySQL en el mismo servidor, sin exposición directa a internet
  (`bind-address 127.0.0.1`).
- **Acceso remoto:** únicamente vía VPN (Tailscale). No se publican puertos
  abiertos a internet más allá de lo estrictamente necesario.

## Módulos del sistema

1. **Autenticación y seguridad** — login, verificación en dos pasos (2FA),
   control de sesiones, roles y permisos.
2. **Dashboard principal** — panel de indicadores clave (KPIs) del Jefe de
   Zona.
3. **Gráficas e indicadores** — visualización interactiva, filtrable por
   fecha/categoría.
4. **Buscador avanzado** — búsqueda y filtrado sobre la información.
5. **CRUD de información** — alta, consulta, edición y eliminación de
   registros (pendiente de definir el modelo de datos final).
6. **Reportes y exportación** — PDF/Excel.
7. **Auditoría y bitácora** — quién accede, qué consulta, qué modifica.
8. **Administración de usuarios** (preparado a futuro, no activo en v1).

## Requisitos de seguridad (no negociables)

- Nunca exponer la aplicación directamente a internet sin VPN.
- 2FA obligatorio para el login. **Excepción vigente:** apagado con
  `TWO_FACTOR_ENABLED=false` mientras se usa una sola cuenta compartida
  (decisión del Jefe de Zona, oct 2026); se reactiva con cuentas individuales.
- Roles y permisos desde el día uno, aunque hoy solo exista un usuario.
- Toda acción de creación/edición/eliminación debe quedar registrada en la
  bitácora de auditoría (`activitylog`).
- MySQL solo accesible desde `localhost`.
- Acceso SSH al servidor solo por llave, nunca por contraseña.
- No incluir credenciales, tokens ni datos sensibles en el repositorio; usar
  siempre `.env` (y mantenerlo fuera de git).

## Convenciones de código

- Seguir el estándar PSR-12 y las convenciones propias de Laravel
  (`php artisan make:` para generar controladores, modelos, migraciones,
  requests, etc.).
- Validación de datos siempre en Form Requests (`app/Http/Requests`), nunca
  validación inline en el controlador.
- Autorización de acciones vía Policies (`app/Policies`) apoyadas en los
  roles de Spatie.
- Componentes Blade reutilizables en `resources/views/components` para
  layout, tarjetas de indicadores, tablas, etc.
- Nombrar el CRUD genérico de referencia como módulo desechable/adaptable:
  al llegar el modelo de datos real, se ajustan migración, requests y vistas
  en lugar de crear un módulo nuevo desde cero.

## Comandos útiles

```bash
# Instalar dependencias
composer install
npm install

# Levantar entorno local
php artisan serve
npm run dev

# Migraciones y seeders (roles, catálogo de estaciones y menú inicial de secciones;
# no crea usuarios). MySQL en todos los entornos; las pruebas usan la base
# dashboard_jefe_zona_test (phpunit.xml).
php artisan migrate --seed

# Provisionar la cuenta compartida (Jefe de Zona + Administrador; interactivo, sin
# contraseña por defecto)
php artisan app:create-zone-chief

# Orden de las filas en los oficios de asistencia (lee
# storage/app/private/imports/documentos-autogenerados/orden_oficio.json)
php artisan asistencia:importar-orden-oficio

# Personal también se importa arrastrando el Excel en RR.HH. > Personal; por consola:
php artisan app:import-agenda-zona-oriente

# Carga retroactiva: crea, dentro de la sección, una tabla por cada hoja con datos de los Excel (xlsx/xls) ya
# guardados en los documentos de las secciones. Idempotente (no repite hojas ya
# cargadas), transaccional por archivo, deshacible desde la pestaña Cargas.
# --dry-run solo lista lo que haría; --seccion=<slug> limita a una sección
php artisan secciones:importar-libros-existentes [--dry-run] [--seccion=slug]

# Generar recursos
php artisan make:model NombreModelo -mcr
php artisan make:request NombreRequest

# Pruebas
php artisan test
```

## Oficios de asistencia autogenerados (.docx + .pdf)

Tras capturar la asistencia, la estación genera su oficio desde la pantalla de
captura; Administrador/Jefe de Zona generan el oficio de zona desde
*Asistencia Zona Oriente* cuando todas las estaciones (incluido Edificio Zonal
Este) capturaron. Las plantillas Word editables viven en `resources/documentos/`
(`asistencia_estacion.docx`, `asistencia_zona.docx`; scripts de origen en
`resources/documentos/build/`) y el texto fijo/firmantes en
`config/asistencia_documentos.php`. El PDF se obtiene convirtiendo el mismo
.docx con **LibreOffice headless**: requiere `libreoffice-writer` y `fonts-noto`
en el VPS y `ASISTENCIA_SOFFICE_PATH` en `.env`. Los archivos se guardan en
disco privado y solo se descargan por rutas autorizadas.

## Secciones dinámicas (tablas + documentos)

Decisión del Jefe de Zona (oct 2026): en lugar de un módulo a la medida por
concepto, cada concepto del menú es una **sección dinámica**
(`app/Services/Secciones`, `app/Http/Controllers/Secciones`):

- El administrador crea, renombra, mueve, oculta o elimina (baja lógica)
  grupos y secciones desde *Administrar menú*. `config/navegacion.php` solo
  define el catálogo inicial (`MenuSeeder`) y los módulos fijos con ruta
  propia (Personal y Asistencia, que dependen de modelos estructurados y
  generan oficios).
- Las secciones se anidan como carpetas (`secciones.parent_id`, máx.
  `Seccion::PROFUNDIDAD_MAXIMA` = 6 niveles): p. ej. Sección > Estación >
  Persona. Cada subsección es una sección completa (sus tablas, documentos y
  cargas); hereda el grupo del menú de su raíz (`MenuService` lo mantiene al
  mover) y se mueve con *Editar > Ubicación*. Quitar una sección quita también
  sus subsecciones (baja lógica). El menú solo despliega la rama abierta; el
  buscador las encuentra todas con su ruta.
- *Editar > Solo documentos* (`secciones.solo_documentos`) quita la parte de
  tabla de una sección: sin tabla, columnas, exportación ni cargas, y los
  archivos que se suban solo se guardan (no se leen sus tablas). Los datos ya
  capturados se conservan si se vuelve a desactivar.
- Tipo de columna *Teléfono*: guarda el valor como `983 168 7870`
  (10 dígitos; acepta guiones, puntos, paréntesis, +52/044/01 y extensión
  "ext. 123"). En captura manual rechaza lo que no sea un teléfono de 10
  dígitos; al importar o cambiar el tipo conserva el texto original si no
  puede convertirlo. Al importar se sugiere por el encabezado (Teléfono,
  Celular…) o por los separadores de los valores.
- Cada sección tiene una o varias tablas (`seccion_tablas`; la primera se
  crea sola con el nombre "Principal"). Cada tabla es configurable
  (`seccion_columnas`: texto, número, fecha, sí/no, lista; valores en
  `seccion_filas.datos` JSON) con alta/edición/baja manual, búsqueda, orden
  y exportación a Excel (una tabla por archivo), más un repositorio de
  documentos privado por sección. La tabla se elige con `?tabla=<id>` (o el
  campo oculto `tabla` de los formularios); sin él se usa la primera
  (`Seccion::tablaActiva()`). Con más de una, la pestaña *Tabla* muestra
  sub-pestañas con el conteo de filas. `seccion_id` se conserva
  denormalizado en columnas y filas (asistente, dashboard, documentos).
  `Seccion::columnas()` y `filas()` son agregados de todas las tablas: para
  pintar o editar usar `SeccionTabla::columnas()` / `filas()`.
- Arrastrar un documento: Excel/CSV/tablas de Word se leen sin IA
  (`ExtractorDocumentos`); se abre una vista previa con mapeo de columnas y
  modo (agregar / actualizar por columna llave / reemplazar). Si la tabla no
  existe se crea sola. Cada carga aplicada se puede deshacer (solo la más
  reciente de la sección).
- "Extraer con IA" (PDF escaneado, foto, Word sin tablas) usa la Claude API
  con salidas estructuradas y entra a la misma vista previa. Opcional:
  apagado si `ANTHROPIC_API_KEY` está vacío.
- Un Excel con varias hojas se sube una sola vez: la vista previa muestra todas las hojas (desmarcables, con mapeo por hoja) y crea una tabla por hoja DENTRO de la misma sección, sin crear entradas de menú (si la sección ya tiene una tabla con el nombre de la hoja, carga en ella; la tabla "Principal" vacía se reutiliza para la primera hoja). Una carga = una importación, un evento resumen y un solo deshacer, que da de baja las tablas creadas que sigan vacías (`LibroImportacionService`, `cambios.tablas_creadas`, `resumen.hojas[].tabla_id`; máx. `secciones.max_hojas_importacion`). La carga de un solo documento va a la tabla elegida al soltarlo (`?tabla=`) o a la primera. El asistente recibe el catálogo por tabla y `consultar_seccion` acepta `tabla`.
- Pestaña *Enlaces* (`seccion_enlaces`, también en secciones "solo documentos"): título, URL http(s) y nota por enlace, con alta/edición/baja y bitácora; se abren con `rel="noopener noreferrer"` y se rechazan otros esquemas (javascript:, ftp:...).
- *Administrar menú*: las ramas con subsecciones arrancan compactadas (flecha ▸ por sección, *Expandir/Compactar todo*, buscador por nombre; el estado se recuerda en la sesión del navegador y al guardar se abre la rama editada).
- Documentos vinculados a la nube: en la pestaña Documentos, *Vincular a la nube* pega el enlace "Compartir" (cualquiera con el enlace, solo lectura) de Google Drive/Docs, OneDrive/SharePoint o Dropbox; sirve para archivos de cuentas distintas sin OAuth por proveedor. `secciones:sincronizar-nube` (scheduler, cada `SECCIONES_SYNC_MINUTOS`; requiere el cron único de `schedule:run`) descarga de nuevo, compara sha256 y, si cambió, reemplaza el archivo y recarga las tablas que ya salían de él (actualizar por columna llave; sin llave solo se reemplaza el documento y queda un aviso). Solo se descargan hosts de `secciones.nube.hosts_permitidos` (también tras redirecciones), con tope de tamaño y la misma validación que una subida; si falla se conserva la versión anterior. Eventos de bitácora: `documento_vinculado_nube`, `documento_sincronizado`, `sincronizacion_fallida`, `documento_desvinculado_nube`. El enlace público expone el archivo a quien lo tenga: lo debe aceptar el área de TIC.
- Las importaciones masivas registran un evento resumen en la bitácora
  (`importacion_aplicada` / `importacion_deshecha`), no una entrada por fila.

Los módulos fijos anteriores (Rol de vacaciones, Escaleras eléctricas,
Elevadores, Estatus de vías y andenes, Flujo de pasajeros, Gasto energético)
se retiraron y hoy son secciones dinámicas con el mismo nombre.

## Asistente de preguntas (Claude API)

El dashboard incluye un asistente que responde preguntas sobre la
información (`app/Services/Asistente`). Claude nunca recibe la base completa:
solo puede pedir consultas de solo lectura acotadas (herramientas) que el
servidor ejecuta, y recibe únicamente el resultado. Requiere
`ANTHROPIC_API_KEY`; límites en `ASISTENTE_LIMITE_POR_MINUTO` /
`ASISTENTE_LIMITE_DIARIO`. Cada pregunta queda en la bitácora.

## Cuenta compartida y 2FA

Por decisión del Jefe de Zona (oct 2026) hay **una sola cuenta** con roles
Jefe de Zona + Administrador que usa todo el equipo, y el 2FA está apagado
con `TWO_FACTOR_ENABLED=false` (el código de 2FA sigue completo). Volver a
`true` en cuanto existan cuentas individuales. El rol Estación existe pero
sin cuentas.

## Roles del proyecto

- **Equipo de desarrollo:** análisis, desarrollo, despliegue, mantenimiento
  técnico y seguridad.
- **Jefe de Zona:** usuario final; valida requerimientos de información y
  entregables.
- **Área de TIC:** acompañamiento y validación de buenas prácticas de
  seguridad institucional.

## Referencia

Este archivo resume las decisiones documentadas en el informe técnico del
proyecto ("Informe_Tecnico_Dashboard_Jefe_Zona_TrenMaya"). Ante cualquier
duda de alcance, tiempos o infraestructura, ese documento es la fuente
completa.

## Agentes del proyecto (Cursor)

Los subagentes viven en `.cursor/agents/` (formato Cursor). Las reglas
detalladas en `.cursor/rules/` (`.mdc`). Los flujos invocables en
`.cursor/skills/` (`/review`, `/fix-issue`).

| Agente | Uso |
|---|---|
| `code-writter` | Escribe código (features, CRUD, tests) |
| `code-reviewer` | Revisa calidad/convenciones **antes de cada commit** |
| `security-auditor` | Auditoría de seguridad (auth, 2FA, roles, uploads, infra) |
| `ux-ui-desginer` | Vistas Blade + Tailwind + Alpine (dashboard, tablas, forms) |

Pipeline habitual: `code-writter` → `code-reviewer` (+ `security-auditor` si toca auth/roles/input/infra) → commit.
