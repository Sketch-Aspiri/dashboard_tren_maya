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
