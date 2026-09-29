<?php

/*
 * Catálogo del menú según "Dashboard Tren Maya.pptx".
 *
 * - 'items': módulos que ya existen (ruta real). 'patron'/'excepto' son
 *   patrones de routeIs() para marcar el enlace activo; 'roles' quién lo ve.
 * - 'pendientes': conceptos del PPT sin módulo ni enlace todavía. Cada uno
 *   abre una página "pendiente de agregar información"
 *   (SeccionPendienteController) visible solo para Jefe de Zona y
 *   Administrador. Al llegar la información real, se mueve el concepto de
 *   'pendientes' a 'items' con su ruta.
 * - 'patrones': patrones de routeIs() que marcan activo el grupo.
 */

$zona = ['Jefe de Zona', 'Administrador'];
$todos = ['Jefe de Zona', 'Administrador', 'Estación'];

return [
    'roles_pendientes' => $zona,

    'grupos' => [
        [
            'id' => 'cronograma-de-informes',
            'label' => 'Cronograma de informes',
            'patrones' => [],
            'items' => [],
            'pendientes' => [
                'Distribución de trabajo y funciones',
                'Seguimiento a documentos recibidos (Mesa de entrada)',
                'Seguimiento de actividades (correo y pend.)',
                'Cronograma de actividades',
            ],
        ],
        [
            'id' => 'rrhh',
            'label' => 'RR.HH.',
            'patrones' => ['agenda.*', 'asistencia.*'],
            'items' => [
                ['label' => 'Personal', 'ruta' => 'agenda.personal.index', 'patron' => 'agenda.personal.*', 'roles' => $zona],
                ['label' => 'Rol de vacaciones', 'ruta' => 'agenda.vacaciones.index', 'patron' => 'agenda.vacaciones.*', 'roles' => $zona],
                ['label' => 'Asistencia Zona Oriente', 'ruta' => 'asistencia.zona.index', 'patron' => 'asistencia.zona.*', 'roles' => $zona],
                ['label' => 'Captura de asistencia', 'ruta' => 'asistencia.captura.index', 'patron' => 'asistencia.captura.*', 'roles' => ['Estación', 'Administrador']],
            ],
            'pendientes' => [
                'Relación nominal',
                'Hojas de datos del personal',
                'Organigrama corporativo',
                'Organigrama estaciones',
                'Bases de datos ZO',
                'Rol horarios',
                'Expedientes',
                'Cursos',
            ],
        ],
        [
            'id' => 'recursos-materiales',
            'label' => 'Recursos materiales',
            'patrones' => ['controles.*'],
            'items' => [
                ['label' => 'Escaleras eléctricas', 'ruta' => 'controles.escaleras-electricas.index', 'patron' => 'controles.escaleras-electricas.*', 'roles' => $todos],
                ['label' => 'Elevadores', 'ruta' => 'controles.elevadores.index', 'patron' => 'controles.elevadores.*', 'roles' => $todos],
                ['label' => 'Estatus de vías y andenes', 'ruta' => 'controles.estatus-vias-andenes.index', 'patron' => 'controles.estatus-vias-andenes.*', 'roles' => $todos],
            ],
            'pendientes' => [
                'Inventario edificio',
                'Resguardos individuales',
                'Entrega y recepción edificio',
                'Relación del material donado',
                'Relación del material sobrante de obra',
                'Drive de observaciones infraestructura ZO',
                'Drive de observaciones de equipo electromecánico de la ZO',
                'Drive trabajos realizados por AIFA',
                'Drive vehículos, material',
                'Drive de act. de mantenimiento de estaciones General',
                'Drive de apoyo de cuadrillas militares a estaciones',
            ],
        ],
        [
            'id' => 'recursos-financieros',
            'label' => 'Recursos financieros',
            'patrones' => ['estadisticas.*'],
            'items' => [
                ['label' => 'Flujo de pasajeros', 'ruta' => 'estadisticas.index', 'patron' => 'estadisticas.*', 'excepto' => 'estadisticas.gasto-energetico.*', 'roles' => $todos],
                ['label' => 'Gasto energético', 'ruta' => 'estadisticas.gasto-energetico.index', 'patron' => 'estadisticas.gasto-energetico.*', 'roles' => $todos],
            ],
            'pendientes' => [
                'Drive comprobaciones Diesel',
                'Drive comprobaciones agua purificada',
                'Drive de expediente de recursos ejercidos por esta dirección',
                'Drive de comprobaciones de fondo de mantenimiento',
                'Drive de observaciones de los equipos electromagnéticos',
                'Drive del estatus de los equipos electromagnéticos',
                'Estatus locales comerciales',
                'Drive excursiones',
            ],
        ],
        [
            'id' => 'sgd-fis',
            'label' => 'SGD. Fis',
            'patrones' => [],
            'items' => [],
            'pendientes' => [
                'Directorio telefónico',
                'Drive fumigación',
                'Drive de verificación extintores',
                'Programa capacitación anual',
                'Drive estatus siniestros',
                'Drive de check list',
                'Despliegue de la GN',
            ],
        ],
        [
            'id' => 'otros',
            'label' => 'Otros',
            'patrones' => [],
            'items' => [],
            'pendientes' => [
                'Manuales y reglamentos',
                'Esquemas de vías',
                'Mapa del Tren Maya',
            ],
        ],
    ],
];
