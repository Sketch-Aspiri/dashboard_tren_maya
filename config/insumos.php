<?php

/*
 * Parámetros del módulo "Solicitud de insumos" (fondo rotatorio).
 *
 * - fondo_zona: total mensual del fondo de la zona (suma de todas las estaciones).
 * - limite_estacion: tope mensual de cada estación, con IVA. 10 estaciones x
 *   $5,000 = $50,000.
 * - iva_defecto: tasa (%) que se aplica a un proveedor sin IVA configurado.
 * - tasas_iva: tasas (%) que la administración puede asignar a un proveedor.
 */

return [
    'fondo_zona' => 50000.00,
    'limite_estacion' => 5000.00,
    'iva_defecto' => 8,
    'tasas_iva' => [8, 16],
];
