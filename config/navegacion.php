<?php

/*
 * Menú principal según "Dashboard Tren Maya.pptx".
 *
 * - 'items': módulos FIJOS con ruta propia (hoy vacío: Personal y Asistencia
 *   siguen existiendo por URL pero no aparecen en el menú). 'patron' /
 *   'excepto' son patrones de routeIs() para marcar el enlace activo;
 *   'roles' quién lo ve.
 * - 'secciones': catálogo INICIAL de secciones dinámicas. MenuSeeder las
 *   crea en la base (tablas menu_grupos / secciones) una sola vez; después
 *   el administrador las renombra, mueve, oculta, elimina o agrega desde
 *   "Administrar menú". Cambiar esta lista no altera una base ya sembrada.
 * - 'patrones': patrones de routeIs() que marcan activo el grupo.
 */

return [
    'grupos' => [
        [
            'slug' => 'cronograma-de-informes',
            'nombre' => 'Cronograma de informes',
            'patrones' => [],
            'items' => [],
            'secciones' => [
                'Distribución de trabajo y funciones',
                'Seguimiento a documentos recibidos (Mesa de entrada)',
                'Seguimiento de actividades (correo y pend.)',
                'Cronograma de actividades',
            ],
        ],
        [
            'slug' => 'rrhh',
            'nombre' => 'RR.HH.',
            'patrones' => ['agenda.*', 'asistencia.*'],
            'items' => [],
            'secciones' => [
                'Rol de vacaciones',
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
            'slug' => 'recursos-materiales',
            'nombre' => 'Recursos materiales',
            'patrones' => [],
            'items' => [],
            'secciones' => [
                'Escaleras eléctricas',
                'Elevadores',
                'Estatus de vías y andenes',
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
            'slug' => 'recursos-financieros',
            'nombre' => 'Recursos financieros',
            'patrones' => [],
            'items' => [],
            'secciones' => [
                'Flujo de pasajeros',
                'Gasto energético',
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
            'slug' => 'sgd-fis',
            'nombre' => 'SGD. Fis',
            'patrones' => [],
            'items' => [],
            'secciones' => [
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
            'slug' => 'otros',
            'nombre' => 'Otros',
            'patrones' => [],
            'items' => [],
            'secciones' => [
                'Manuales y reglamentos',
                'Esquemas de vías',
                'Mapa del Tren Maya',
            ],
        ],
    ],
];
