<?php

/*
 * Parámetros del módulo "Solicitud de insumos" (fondo rotatorio).
 *
 * - fondo_zona: total mensual del fondo de la zona (suma de todas las estaciones).
 * - limite_estacion: tope mensual de cada estación, con IVA. 10 estaciones x
 *   $5,000 = $50,000.
 * - iva_defecto: tasa (%) que se aplica a un proveedor sin IVA configurado.
 * - tasas_iva: tasas (%) que la administración puede asignar a un proveedor.
 * - rfc_receptor: RFC al que deben venir expedidas las facturas del fondo.
 * - tolerancias de la comprobación de facturas (pesos y porcentaje).
 */

return [
    'fondo_zona' => 50000.00,
    'limite_estacion' => 5000.00,
    'iva_defecto' => 8,
    'tasas_iva' => [8, 16],
    'rfc_receptor' => 'TMA220603D80',
    'tolerancia_redondeo' => 0.05,
    'tolerancia_importe' => 0.10,
    'tolerancia_precio_alerta' => 0.05,
    // Hoja de validación de la comprobación (PDF por factura): texto fijo y dirección.
    // Consulta del estado de cada factura en el servicio público del SAT.
    'sat' => [
        'consulta_automatica' => (bool) env('INSUMOS_SAT_AUTOMATICO', true),
        'timeout' => 12,
    ],
    'comprobacion' => [
        'maximo_estaciones_en_texto' => 4,
        // Quienes firman por defecto la hoja de validación; en pantalla se pueden cambiar.
        'firmantes' => [
            'recibio' => ['cargo' => 'El Director de Gestión Territorial Zona Oriente “Tren Maya S.A. de C.V.”', 'nombre' => 'Lic. Miguel Ángel Milanez Navarrete'],
            'autorizo' => ['cargo' => 'El Coord. Gral. de Gest. de Infra. Ferroviaria de “Tren Maya S.A. de C.V.”', 'nombre' => 'Lic. Francisco Javier Moreno Carrillo'],
        ],
        'gs_path' => env('INSUMOS_GS_PATH', 'gs'),
    ],
];
