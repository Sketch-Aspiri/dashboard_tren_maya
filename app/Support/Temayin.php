<?php

namespace App\Support;

use App\Models\CatalogoInsumo;
use App\Models\Cotizacion;
use App\Models\Empleado;
use App\Models\Factura;
use App\Models\Proveedor;
use App\Models\Seccion;
use App\Models\SolicitudInsumo;
use App\Models\User;

/**
 * Temayin, asistente de preguntas frecuentes (sin IA ni servidor: las
 * respuestas son fijas). Igual que el de Tickets: cada pregunta tiene un
 * público y, si lleva enlace, solo se ofrece a quien puede abrir esa ruta
 * (mismas policies/gates que el menú y las pestañas de insumos).
 */
final class Temayin
{
    /**
     * Preguntas que ve este usuario, con su enlace ya resuelto.
     *
     * @return array<string, array{q: string, a: string, url: ?string, enlace: ?string}>
     */
    public function preguntas(User $user): array
    {
        $publicos = $this->publicos($user);

        return collect($this->catalogo())
            ->filter(fn (array $faq) => $publicos[$faq['publico']] ?? false)
            ->map(fn (array $faq) => [
                'q' => $faq['q'],
                'a' => $faq['a'],
                'url' => isset($faq['ruta']) ? route($faq['ruta']) : null,
                'enlace' => $faq['enlace'] ?? null,
            ])
            ->all();
    }

    /**
     * Enlace para reportar un problema con el sistema (Soporte, en Tickets).
     */
    public function urlSoporte(): string
    {
        return (string) config('services.tickets.soporte_url');
    }

    /**
     * @return array<string, bool>
     */
    private function publicos(User $user): array
    {
        return [
            'todos' => true,
            'zona' => $user->can('view-dashboard'),
            'estacion' => $user->hasRole(Roles::ESTACION),
            'personal' => $user->can('viewAny', Empleado::class),
            'asistencia_zona' => $user->can('view-asistencia-zona'),
            'captura' => $user->hasAnyRole([...Roles::ZONA, Roles::ESTACION]),
            'menu' => $user->can('create', Seccion::class),
            'insumos' => $user->can('viewAny', SolicitudInsumo::class),
            'insumos_zona' => $user->can('viewAny', SolicitudInsumo::class) && ! $user->hasRole(Roles::ESTACION),
            'cotizaciones' => $user->can('manage', Cotizacion::class),
            'facturas' => $user->can('manage', Factura::class),
            'coordinacion' => $user->can('consolidate', SolicitudInsumo::class),
            'catalogo' => $user->can('manage', CatalogoInsumo::class),
            'proveedores' => $user->can('manage', Proveedor::class),
        ];
    }

    /**
     * @return array<string, array{publico: string, q: string, a: string, ruta?: string, enlace?: string}>
     */
    private function catalogo(): array
    {
        return [
            'que_es' => [
                'publico' => 'todos',
                'q' => __('¿Qué puedo hacer en este sistema?'),
                'a' => __('Es el tablero de la Zona Oriente: personal, control de asistencia diaria, las secciones del menú con sus tablas y documentos, y el fondo rotatorio de insumos (solicitudes, cotizaciones, facturas y su comprobación). Usa el buscador de arriba para llegar rápido a cualquier sección.'),
            ],
            'dashboard' => [
                'publico' => 'zona',
                'q' => __('¿Qué muestra el dashboard principal?'),
                'a' => __('Los indicadores clave (asistencia capturada hoy, personal activo, secciones con información), el avance de la asistencia por estación, cómo van las solicitudes de insumos del mes y lo que se actualizó recientemente. Cada tarjeta con «Ver detalle» te lleva a su módulo.'),
                'ruta' => 'dashboard',
                'enlace' => __('Ir al dashboard'),
            ],
            'inicio_estacion' => [
                'publico' => 'estacion',
                'q' => __('¿Qué veo en el inicio de mi estación?'),
                'a' => __('Si ya capturaste la asistencia de hoy y las secciones del menú habilitadas para tu estación. Las secciones aparecen cuando el Jefe de Zona las aprueba.'),
                'ruta' => 'dashboard',
                'enlace' => __('Ir al inicio'),
            ],
            'buscar' => [
                'publico' => 'todos',
                'q' => __('No encuentro una sección, ¿cómo la busco?'),
                'a' => __('Escribe parte del nombre en «Buscar en el menú…» (arriba a la derecha; en celular, dentro del menú). No importan los acentos: «vacaciones» encuentra «Rol de vacaciones».'),
            ],
            'personal' => [
                'publico' => 'personal',
                'q' => __('¿Dónde consulto o actualizo al personal?'),
                'a' => __('En Personal. Puedes buscar, filtrar por estación y estatus, abrir la ficha de cada persona e importar el Excel de la base de datos arrastrándolo a la página.'),
                'ruta' => 'agenda.personal.index',
                'enlace' => __('Abrir Personal'),
            ],
            'captura' => [
                'publico' => 'captura',
                'q' => __('¿Cómo capturo la asistencia del día?'),
                'a' => __('En Captura de asistencia marca a cada persona (presente, descanso, vacaciones, comisión…), agrega comisionados si los hay y revisa la vista previa del oficio. Al guardar, la Zona ve tu avance en tiempo real.'),
                'ruta' => 'asistencia.captura.index',
                'enlace' => __('Abrir Captura de asistencia'),
            ],
            'asistencia_zona' => [
                'publico' => 'asistencia_zona',
                'q' => __('¿Cómo sé qué estaciones ya capturaron asistencia?'),
                'a' => __('En Asistencia Zona Oriente ves cada estación con su avance del día; abre una para ver el detalle por persona y descargar el oficio. El dashboard también te avisa cuántas faltan.'),
                'ruta' => 'asistencia.zona.index',
                'enlace' => __('Abrir Asistencia Zona Oriente'),
            ],
            'secciones' => [
                'publico' => 'todos',
                'q' => __('¿Cómo cargo información a una sección del menú?'),
                'a' => __('Abre la sección y arrastra un Excel, Word o PDF: si trae una tabla, el sistema la crea sola. También puedes capturar registros a mano en la hoja de cálculo y adjuntar documentos.'),
            ],
            'menu' => [
                'publico' => 'menu',
                'q' => __('¿Cómo agrego o reordeno secciones del menú?'),
                'a' => __('En Administrar menú creas grupos y secciones, los reordenas y decides qué secciones ven las estaciones.'),
                'ruta' => 'secciones.administrar',
                'enlace' => __('Abrir Administrar menú'),
            ],
            'solicitud_estacion' => [
                'publico' => 'estacion',
                'q' => __('¿Cómo pido insumos para mi estación?'),
                'a' => __('En Insumos se abre tu solicitud del mes. Busca cada artículo en el catálogo o escríbelo, pon cantidad y justificación (es obligatoria) y guarda. Cuando esté completa, «Enviar a la Zona». El total por estación no puede pasar de $5,000 con IVA.'),
                'ruta' => 'insumos.index',
                'enlace' => __('Abrir mi solicitud'),
            ],
            'inventario_aviso' => [
                'publico' => 'insumos',
                'q' => __('¿Por qué mi solicitud dice que ya tengo un artículo?'),
                'a' => __('El sistema compara lo que pides con el inventario de la estación (resguardos). La herramienta y el equipo de protección cuentan como existencia; de los consumibles solo importa si se entregaron en los últimos meses. Si de verdad lo necesitas, explícalo en la justificación.'),
                'ruta' => 'insumos.inventario.index',
                'enlace' => __('Ver el inventario'),
            ],
            'solicitudes_zona' => [
                'publico' => 'insumos_zona',
                'q' => __('¿Cómo reviso las solicitudes de insumos?'),
                'a' => __('En Insumos ves el tablero del mes con el estado de cada estación. Abre una solicitud para completar precio, partida y proveedor, y apruébala o regrésala con observaciones.'),
                'ruta' => 'insumos.index',
                'enlace' => __('Abrir el tablero de insumos'),
            ],
            'cotizaciones' => [
                'publico' => 'cotizaciones',
                'q' => __('¿Para qué sirven las cotizaciones?'),
                'a' => __('Ahí cargas las cotizaciones de los proveedores (también se pueden leer con IA) y aceptas o rechazas cada renglón. Lo aceptado alimenta los precios de las solicitudes.'),
                'ruta' => 'insumos.cotizaciones.index',
                'enlace' => __('Abrir Cotizaciones'),
            ],
            'facturas' => [
                'publico' => 'facturas',
                'q' => __('¿Cómo cargo una factura?'),
                'a' => __('En Facturas sube el XML junto con su PDF (el PDF es obligatorio; se emparejan por nombre). El sistema lee los conceptos, los relaciona con las partidas aprobadas y marca lo que no cuadra.'),
                'ruta' => 'insumos.facturas.index',
                'enlace' => __('Abrir Facturas'),
            ],
            'sat' => [
                'publico' => 'facturas',
                'q' => __('¿Cómo sé si una factura está vigente en el SAT?'),
                'a' => __('Se consulta sola al cargarla y otra vez cada día. En la ficha ves «Vigente», «Cancelada» o «No encontrada», con la fecha de la consulta, y puedes usar «Verificar en el SAT» para repetirla. Una factura cancelada no entra a la comprobación.'),
                'ruta' => 'insumos.facturas.index',
                'enlace' => __('Abrir Facturas'),
            ],
            'comprobacion' => [
                'publico' => 'facturas',
                'q' => __('¿Cómo genero la comprobación en PDF?'),
                'a' => __('Abre la factura y ve a «Comprobación en PDF»: revisa la vista previa, escribe cargo y nombre de quienes firman y, si quieres, sube la firma digital. El PDF junta factura, hoja de validación, factura y validación del XML. También puedes generar la del mes completo.'),
                'ruta' => 'insumos.facturas.index',
                'enlace' => __('Abrir Facturas'),
            ],
            'coordinacion' => [
                'publico' => 'coordinacion',
                'q' => __('¿Cómo envío lo del mes a Coordinación?'),
                'a' => __('En Coordinación ves el consolidado de las solicitudes aprobadas por partida y proveedor; desde ahí se envía y se registra la respuesta.'),
                'ruta' => 'insumos.coordinacion.index',
                'enlace' => __('Abrir Coordinación'),
            ],
            'catalogo' => [
                'publico' => 'catalogo',
                'q' => __('¿Qué es el catálogo de insumos?'),
                'a' => __('La lista de artículos con su último precio, partida presupuestal y proveedor. Aprende sola: al aprobar una solicitud se actualiza con lo que validó la Zona.'),
                'ruta' => 'insumos.catalogo.index',
                'enlace' => __('Abrir Catálogo'),
            ],
            'proveedores' => [
                'publico' => 'proveedores',
                'q' => __('¿Dónde defino el IVA de un proveedor?'),
                'a' => __('En Proveedores e IVA. Cada proveedor factura con su propio IVA (8 % o 16 %) y puede retener ISR; uno sin IVA definido se resalta y usa la tasa por defecto.'),
                'ruta' => 'insumos.proveedores.index',
                'enlace' => __('Abrir Proveedores e IVA'),
            ],
            'tema' => [
                'publico' => 'todos',
                'q' => __('¿Puedo usar el modo oscuro?'),
                'a' => __('Sí. Usa el botón del sol/luna junto a tu nombre (en celular, al final del menú): cambia entre el tema del sistema, claro y oscuro. Se recuerda en este navegador.'),
            ],
            'soporte' => [
                'publico' => 'todos',
                'q' => __('Algo no funciona, ¿a quién le aviso?'),
                'a' => __('Crea un ticket a Soporte del sistema en el sistema de Tickets, con lo que estabas haciendo y, si puedes, una captura de pantalla.'),
            ],
        ];
    }
}
