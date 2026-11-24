<?php

/*
 * Parámetros del módulo "PTAR" (plantas de tratamiento de aguas residuales por estación).
 *
 * - plantas: catálogo de las 10 PTAR de la Zona (clave = nombre de la estación en `estaciones`).
 * - equipos: equipos que se reportan cada mes, en el orden del Excel.
 * - actividades: actividades de mantenimiento con su última fecha.
 * - dias_alerta_mantenimiento: días sin mantenimiento a partir de los cuales se marca vencido.
 * - evidencia_kb / max_evidencias: fotos por registro mensual.
 */

$nom001 = 'NOM-001-SEMARNAT-2021';
$nom003 = 'NOM-003-SEMARNAT-1997';
$nom004 = 'NOM-004-SEMARNAT-2002';

return [
    'plantas' => [
        'Puerto Morelos' => ['numero' => 23, 'tramo' => 'T5', 'normas' => [$nom001, $nom003]],
        'Playa del Carmen' => ['numero' => 24, 'tramo' => null, 'normas' => [$nom001, $nom003]],
        'Tulum' => ['numero' => 25, 'tramo' => null, 'normas' => [$nom001, $nom003]],
        'Tulum Aeropuerto' => ['numero' => 26, 'tramo' => 'T6', 'normas' => [$nom001, $nom003, $nom004]],
        'Felipe Carrillo Puerto' => ['numero' => 27, 'tramo' => null, 'normas' => [$nom001, $nom003]],
        'Limones' => ['numero' => 28, 'tramo' => null, 'normas' => [$nom001, $nom003, $nom004]],
        'Bacalar' => ['numero' => 29, 'tramo' => null, 'normas' => [$nom001, $nom003, $nom004]],
        'Chetumal' => ['numero' => 30, 'tramo' => null, 'normas' => [$nom003, $nom004]],
        'Kohunlich' => ['numero' => 31, 'tramo' => 'T7', 'normas' => [$nom001, $nom003]],
        'Edificio Zonal Este' => ['numero' => 37, 'tramo' => 'PCZ', 'normas' => [$nom001, $nom003]],
    ],
    'empresa_operadora' => 'ACQUASU',
    'equipos' => [
        'bomba_sumergible' => 'Bomba sumergible',
        'bomba_centrifuga' => 'Bomba centrífuga',
        'sopladores' => 'Sopladores',
        'difusores' => 'Difusores',
        'panel_control' => 'Panel de control',
    ],
    'actividades' => ['Cárcamo', 'Tolva', 'Canaletas', 'Sedimentador', 'Reactor aerobio'],
    'dias_alerta_mantenimiento' => 90,
    // Día del mes (America/Cancun) en que se recuerda a las estaciones enviar el registro; el Excel se revisa el 7.
    'recordatorio_dia' => 5,
    'max_evidencias' => 6,
    'evidencia_kb' => 8192,
    // Oficio mensual de estatus de las PTAR (Word). Los nombres de quien recibe y firma se capturan al generarlo
    // (o se fijan en el .env); aquí solo viven los textos institucionales que casi no cambian.
    'oficio' => [
        'leyenda' => env('PTAR_OFICIO_LEYENDA', '"2026, Año de Margarita Maza Parada"'),
        'prefijo_numero' => 'TM/UAI/CGGIF/DGTZO',
        'asunto' => 'Presentar las acciones de propuesta para mejorar el estado de conservación de las Plantas Tratadoras de Aguas Residuales ubicadas en los centros de trabajo de la Zona Oriente.',
        'destinatario' => env('PTAR_OFICIO_DESTINATARIO', ''),
        'firmante_cargo' => env('PTAR_OFICIO_FIRMANTE_CARGO', ''),
        'firmante_nombre' => env('PTAR_OFICIO_FIRMANTE_NOMBRE', ''),
        'consideraciones' => [
            'Se recomienda fortalecer el programa de mantenimiento de las PTAR, asegurando la atención oportuna de las actividades preventivas y correctivas identificadas durante las inspecciones.',
            'Asimismo, se propone evaluar la instalación de cubiertas o techados en las plantas de tratamiento, con el propósito de proteger los equipos de la exposición directa a la radiación solar, reducir el sobrecalentamiento de los componentes electromecánicos y eléctricos, prolongar su vida útil y minimizar el ingreso de agua pluvial al sistema.',
            'De igual manera se implemente un plan de acción para que los responsables de la operación mantengan actualizadas las bitácoras de operación, registrando de manera puntual las condiciones de funcionamiento, incidencias y actividades de mantenimiento realizadas, a fin de facilitar la detección temprana de anomalías y la implementación de acciones correctivas oportunas.',
            'Para fortalecer el seguimiento operativo, se recomienda implementar programas de capacitación periódica dirigidos al personal responsable de la operación y mantenimiento de las PTAR, promoviendo el conocimiento técnico necesario para una adecuada operación, el cumplimiento de los procedimientos establecidos y la atención oportuna de las incidencias detectadas.',
            'Finalmente, se recomienda promover una mayor atención y compromiso con la operación de las plantas de tratamiento, reconociendo la importancia de estos sistemas para garantizar el cumplimiento de los parámetros de calidad del agua tratada, la protección del medio ambiente y la continuidad operativa de las instalaciones.',
        ],
    ],
];
