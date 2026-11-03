# Instalación del Dashboard en la PC de una estación (Windows)

Cada estación corre su **propia copia** del Dashboard en una PC con Windows. Las demás
computadoras de la misma estación lo abren desde el navegador con la IP de esa PC
(por ejemplo `http://192.168.1.50`). Más adelante las copias se consolidarán en un
servidor en la nube; ver «Qué sigue» al final.

## Contenido de esta carpeta

| Archivo | Para qué sirve |
|---|---|
| `build-release.ps1` | **Equipo de desarrollo.** Arma el paquete que se lleva a las estaciones. |
| `install.ps1` | Instala todo en la PC de la estación (una sola vez). |
| `update.ps1` | Actualiza una estación ya instalada, con respaldo previo. |
| `backup.ps1` | Respaldo de la base y los documentos (la instalación lo programa cada noche). |
| `restaurar.ps1` | Restaura un respaldo. |
| `desinstalar.ps1` | Quita todo de la PC (servicios, firewall, `C:\dashboard`). Útil si una instalación falla a medias. |
| `comun.ps1` | Funciones compartidas por los scripts (no se ejecuta solo). |
| `plantillas/` | Configuración de Nginx, PHP, MySQL y `.env` que usa el instalador. |
| `estaciones.csv` | Control de qué IP, responsable y versión tiene cada estación. |

---

## A. Preparar el paquete (una vez, en la PC de desarrollo)

Requiere `git`, `php`, `composer` y `npm`, e internet.

1. Confirmar que todo está commiteado en `master` y que las pruebas pasan (`php artisan test`).
2. Abrir PowerShell en esta carpeta y ejecutar:
   ```powershell
   .\build-release.ps1
   ```
3. La primera vez pide que se descargue **a mano** MySQL (su sitio bloquea la descarga automática)
   y se copie a `componentes\`:
   - MySQL 8.0 ZIP (`mysql-8.0.XX-winx64.zip`): https://dev.mysql.com/downloads/mysql/8.0.html
     (opción *Windows (x86, 64-bit), ZIP Archive*; no pide iniciar sesión: usar «No thanks, just start my download»).
   - NSSM se baja solo desde https://nssm.cc/release/nssm-2.24.zip (con respaldo en Chocolatey). Si
     ambos fallan, descargar uno de los dos enlaces a mano y guardarlo como `componentes\nssm-2.24.zip`.
4. Al terminar queda `C:\dashboard-build\est-<versión>\` (y un `.zip`). Se arma en una carpeta corta
   a propósito: dentro de OneDrive las rutas de `vendor\` exceden los 260 caracteres de Windows. Esa carpeta completa es
   lo que se copia, por USB, a cada estación. Pesa cerca de 1 GB (incluye LibreOffice).

> Cada versión nueva repite los pasos 1–2. Todas las estaciones deben quedar en la **misma versión**.

---

## B. Instalar en la PC de la estación

### Requisitos de la PC
- Windows 10 u 11 de 64 bits, con al menos **8 GB de RAM** (mínimo 4 GB) y **15 GB libres** en `C:`.
- Una cuenta de **administrador** de Windows.
- Conectada por cable (o Wi-Fi estable) a la red de la estación, **encendida todo el día**.
- Un disco externo o USB para los respaldos (recomendado).
- Pedir a TIC: una **IP reservada** para esa PC (reservación DHCP en el router o IP fija).

### Pasos
1. Copiar la carpeta del paquete a la PC (por ejemplo a `C:\paquete`).
2. Abrir **PowerShell como administrador** (clic derecho > *Ejecutar como administrador*).
3. Ir a la carpeta y permitir los scripts solo para esta ventana:
   ```powershell
   cd C:\paquete
   Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass
   ```
4. Ejecutar el instalador con el nombre exacto de la estación:
   ```powershell
   .\install.ps1 -Estacion "Bacalar" -DestinoRespaldo "E:\respaldos-dashboard"
   ```
   Estaciones válidas: `Bacalar`, `Chetumal`, `Felipe Carrillo Puerto`, `Kohunlich`, `Limones`,
   `Playa del Carmen`, `Puerto Morelos`, `Tulum`, `Tulum Aeropuerto`, `Edificio Zonal Este`.

   Opciones: `-IP 192.168.1.50` (si no se detecta bien), `-Puerto 8080` (si el 80 está ocupado,
   por ejemplo por IIS), `-DestinoRespaldo` (carpeta o disco externo de los respaldos).
5. A mitad de la instalación pide el **correo y una contraseña nueva** para la cuenta del Jefe de
   Zona. Anótelos; no existe contraseña por defecto.
6. Al final muestra la dirección, por ejemplo `http://192.168.1.50`. Esa es la que se abre desde
   los navegadores de la estación.

La instalación tarda de 10 a 20 minutos. Deja registro en `C:\dashboard\logs\install.log`.

### Qué instala
- `C:\dashboard\app` – la aplicación. `C:\dashboard\datos` – la base de datos.
- Servicios de Windows (`DashboardNginx`, `DashboardPHP1-3`, `DashboardMySQL`) que **arrancan solos**
  al encender la PC o después de un corte de luz.
- Regla de firewall: el puerto web solo acepta equipos de **la misma red local**. MySQL escucha
  únicamente dentro de la propia PC y nunca se abre.
- Tarea programada **«Dashboard – respaldo nocturno»** a las 23:30.
- LibreOffice (convierte los oficios de asistencia a PDF).
- La PC deja de suspenderse mientras está conectada a la corriente.

---

## C. Revisión después de instalar (lista de comprobación)

- [ ] Desde **otra PC** de la estación abre `http://<IP>` e inicia sesión.
- [ ] Reiniciar la PC del servidor: sin hacer nada, el sitio vuelve a abrir.
- [ ] Subir un Excel de prueba a una sección, aplicar la carga y deshacerla (pestaña *Cargas*).
- [ ] Capturar asistencia y generar el oficio de la estación en **Word y PDF**.
- [ ] Ejecutar un respaldo a mano (ver abajo) y confirmar que aparece el `.sql.gz` en el destino.
- [ ] Revisar que `C:\dashboard\app\storage\logs\laravel.log` no tenga errores.
- [ ] Reservar la IP en el router o fijarla en Windows.

## D. Uso diario

- Entrar desde el navegador a la dirección de la estación. No hace falta tocar la PC servidor.
- **No apagar** la PC servidor. Cerrar sesión de Windows sí se puede: los servicios corren aparte.
- Si algo falla: `C:\dashboard\logs\` (`nginx-error.log`, `php-error.log`, `DashboardPHP*.log`).
- Reiniciar el sitio a mano (PowerShell como administrador):
  ```powershell
  Restart-Service DashboardMySQL; Restart-Service DashboardPHP1,DashboardPHP2,DashboardPHP3,DashboardNginx
  ```

## E. Respaldos

- Se hacen solos cada noche en la carpeta/disco indicado con `-DestinoRespaldo`:
  `dashboard_AAAA-MM-DD_HHmm.sql.gz` (se guardan 14 días) y la carpeta `documentos\` (copia espejo).
- A mano: `C:\dashboard\scripts\backup.ps1`
- **Si el disco de respaldo es el mismo de la PC, no protege contra una falla del disco.** Usar un
  disco externo y, cada semana, copiar el respaldo a otro equipo.
- Restaurar (reemplaza la información actual; antes hace un respaldo de seguridad):
  ```powershell
  C:\dashboard\scripts\restaurar.ps1 -Respaldo E:\respaldos-dashboard\dashboard_2026-11-02_2330.sql.gz -Documentos E:\respaldos-dashboard\documentos
  ```
- Conviene **probar una restauración** durante el piloto, no cuando haga falta.

## F. Actualizar a una versión nueva

1. Recibir la carpeta del paquete nuevo (USB).
2. PowerShell como administrador, dentro de esa carpeta:
   ```powershell
   Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass
   .\update.ps1
   ```
3. Hace respaldo, pone el sitio en mantenimiento, reemplaza el código (conserva los datos y el
   `.env`), migra la base y vuelve a encender. Si falla, imprime cómo regresar a la versión anterior
   (queda en `C:\dashboard\app.anterior`).
4. Anotar la versión instalada en `estaciones.csv`.

## G. Seguridad (puntos para el Área de TIC)

- El sitio **no se publica a internet**: sin port-forwarding en el router.
- Acceso web solo desde la red local (firewall de Windows). Se usa **HTTP** dentro de la LAN; si TIC
  exige cifrado en la red local, hay que agregar un certificado interno (se evalúa aparte).
- Los servicios corren con la cuenta sin privilegios `LocalService`, no como administrador.
- Las contraseñas de MySQL se generan al azar en la instalación y viven en `C:\dashboard\app\.env` y
  `C:\dashboard\secretos\` (solo administradores).
- Se recomienda activar **BitLocker** en la PC, mantener Windows actualizado y usar un **UPS**.
- Una sola cuenta compartida y sin 2FA es una excepción vigente por decisión del Jefe de Zona; se
  revierte en cuanto existan cuentas individuales.
- La IA (asistente y «Extraer con IA») viene **apagada**: `ANTHROPIC_API_KEY` vacío.

## H. Problemas frecuentes

| Síntoma | Qué revisar |
|---|---|
| La instalación falló a medias | Ejecutar `.\desinstalar.ps1` (como administrador, desde la carpeta del paquete) y volver a correr `install.ps1`. |
| «El puerto 3307 ya está en uso» | Instalar con `-PuertoMysql 3310` (cualquier puerto libre). |
| «require a PHP version >= 8.4.1» | El paquete trae un PHP viejo. Reconstruirlo con `build-release.ps1` (usa PHP 8.4). |
| «El puerto 80 ya está en uso» | Otro programa (IIS, Skype…). Instalar con `-Puerto 8080`. |
| No abre desde otra PC | ¿Misma red/subred? ¿La IP cambió? Probar `http://<IP>` desde el servidor mismo. |
| Pantalla de error 502 | Los procesos PHP no corren: `Get-Service Dashboard*` y reiniciarlos. |
| El oficio sale solo en Word | LibreOffice no se instaló: revisar `ASISTENCIA_SOFFICE_PATH` en `C:\dashboard\app\.env`. |
| El respaldo falla | Ver `C:\dashboard\logs\backup.log` (casi siempre es el disco externo desconectado). |
| La IP cambió | Reservarla en el router; luego cambiar `APP_URL` en `.env` y ejecutar `php artisan config:cache`. |

---

## Qué sigue: consolidación en la nube

Este esquema es el paso intermedio. Mientras las copias estén separadas:

- Cada estación genera **su** oficio. El **oficio de zona** (que necesita las 9 estaciones capturadas)
  solo es posible cuando todo esté en un mismo servidor.
- **No crear secciones o columnas distintas en cada estación**: si hace falta algo nuevo, se pide al
  equipo de desarrollo y llega en la siguiente versión para todas. Así los datos se podrán unir.

La etapa siguiente es un servidor en la nube (VPS con VPN) con una cuenta por estación y la importación
de los datos de cada copia local. Eso requiere desarrollo adicional (cuentas por estación y exportar/importar)
y se planea por separado.
