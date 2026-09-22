# Dashboard Jefe de Zona — Tren Maya

## Descripción del proyecto

Sistema web (dashboard) de consulta para el Jefe de Zona del Tren Maya. Incluye
panel de indicadores (KPIs), gráficas interactivas, buscador y un módulo CRUD
para la administración de la información. Es la primera versión del sistema:
diseñado para un solo usuario (el Jefe de Zona), pero preparado desde el
diseño para escalar a más usuarios en el futuro.

**Estatus actual:** en desarrollo. Aún no se cuenta con el modelo de datos
final (los campos específicos de la información que manejará el sistema
todavía no han sido entregados). Mientras se espera esa información, el
trabajo se enfoca en la base del sistema: autenticación, roles, layout y un
CRUD genérico de referencia.

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
- 2FA obligatorio para el login.
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

# Migraciones y seeders (roles: Jefe de Zona, Administrador; no crea usuarios)
php artisan migrate --seed

# Provisionar la cuenta real del Jefe de Zona (interactivo, sin contraseña por defecto)
php artisan app:create-zone-chief

# Orden de las filas en los oficios de asistencia (lee
# storage/app/private/imports/documentos-autogenerados/orden_oficio.json)
php artisan asistencia:importar-orden-oficio

# Rol de vacaciones (Agenda Zona Oriente > Rol de vacaciones): importa el ANEXO A de
# vacacionistas desde storage/app/private/imports/rol-vacaciones/*.xlsx (idempotente;
# el año se infiere del nombre del archivo o con --anio=YYYY)
php artisan app:import-rol-vacaciones

# Gasto energético (Estadísticas > Gasto energético): importa la hoja "ZONA ORIENTE" del
# ANEXO B desde storage/app/private/imports/estadisticas/gasto energetico/*.xlsx (idempotente)
php artisan app:import-gasto-energetico

# Controles (Escaleras eléctricas / Elevadores): importa el inventario desde
# storage/app/private/imports/controles/escaleras y elevadores/*.xlsx. Reemplaza el
# contenido completo de ambas tablas en cada corrida (no hay llave natural por equipo).
php artisan app:import-controles

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

## Pendientes bloqueados por información externa

Estos puntos **no se deben construir todavía** porque dependen de
información que aún no ha entregado el Jefe de Zona:

- Modelo de datos final del CRUD (campos reales de la información a
  gestionar).
- Definición exacta de los indicadores/KPIs que irán en el dashboard
  principal.
- Tipos de gráficas específicas y las dimensiones/filtros que necesita ver
  el Jefe de Zona.
- Contenido y formato de los reportes exportables (PDF/Excel).

Mientras tanto, estos módulos se desarrollan con datos de ejemplo (dummy
data) para dejar el patrón de código listo y adaptarlo rápido en cuanto
llegue la información real.

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
