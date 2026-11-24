# Módulo PTAR (plantas de tratamiento de aguas residuales)

Registro mensual y seguimiento de las 10 PTAR de la Zona Oriente. Menú: Recursos materiales → PTAR (`/ptar`).

## Qué hace

- **Captura mensual** (`/ptar`): cada estación abre su registro del mes (hereda modo, equipos y fechas de mantenimiento
  del mes anterior), captura modo de operación, estatus de los 5 equipos, actividades con su última fecha,
  observaciones y hasta 6 fotos, y lo envía. La Zona (Jefe de Zona, Administrador, Edificio) lo aprueba o lo devuelve
  con comentario. Edificio captura la planta de "Edificio Zonal Este".
- **Tablero** con semáforo por planta (verde/ámbar/rojo/gris), mantenimiento vencido (más de
  `ptar.dias_alerta_mantenimiento` días) y hallazgos abiertos. La Zona descarga el **Excel** del mes.
- **Hallazgos** (`/ptar/hallazgos`): problemáticas y propuestas con prioridad, estado, fecha compromiso y tarjeta. La
  Zona puede crear una actividad en Tickets por hallazgo.
- **Oficio mensual en Word** (`/ptar/oficio`): se arma con los hallazgos sin cerrar.
- **Recordatorio** el día `ptar.recordatorio_dia` (5) de cada mes a las estaciones que no han enviado su registro.

## Despliegue

1. `php artisan migrate --force` (crea las tablas `ptar_*`).
2. `php artisan ptar:sincronizar-plantas` (crea las 10 plantas según `config/ptar.php`; es repetible).
3. Carga inicial (una sola vez, con el Excel y el oficio de la Zona copiados al servidor):
   - `php artisan ptar:cargar-historico "<DGTZO_ESTATUS PTAR.xlsx>" --simular`, y sin `--simular` si el resumen es el esperado.
     Lee las hojas JULIO–NOVIEMBRE 2026; el último mes queda en borrador y los anteriores aprobados. No pisa registros existentes.
   - `php artisan ptar:cargar-hallazgos "<oficio.docx>" --simular`, y sin `--simular`. No duplica.
4. `php artisan config:cache && php artisan route:cache && php artisan view:cache`.
5. Variables opcionales del `.env` (ver `.env.example`): `PTAR_OFICIO_LEYENDA`, `PTAR_OFICIO_DESTINATARIO`,
   `PTAR_OFICIO_FIRMANTE_CARGO`, `PTAR_OFICIO_FIRMANTE_NOMBRE` y `ECOSISTEMA_AVISO_PTAR_HORA`. Pueden quedar vacías:
   los datos del oficio se capturan al generarlo.

Las fotos se guardan en el disco privado (`storage/app/private/ptar/evidencias`) y solo se sirven por la ruta autorizada.

## Tickets

El evento `ptar_hallazgo` está en el valor por defecto de `ECOSISTEMA_ACT_EVENTOS` del Dashboard. Para que Tickets cree
la actividad hay que declararlo también allá (`config/ecosistema.php` de Tickets → `actividades.eventos.ptar_hallazgo`,
alcance `estacion`, con su equipo en el `.env`). Sin eso, el botón "Crear actividad en Tickets" no produce nada.

## Notas

- El Excel mensual no incrusta las fotos (indica cuántas hay); el original sí las traía en celdas.
- Los textos institucionales del oficio (asunto, consideración) están en `config/ptar.php` y se pueden editar al generarlo.
- Pruebas: `tests/Feature/Ptar*Test.php`.
