<?php

/*
 * Parámetros del módulo "Registro de agua" (garrafones por estación y mes).
 *
 * - max_compras: compras que puede registrar una estación en un mes (el Excel original trae 4 pagos).
 * - frecuencias: periodicidad del programa de compra.
 * - comprobante_kb: tamaño máximo del comprobante (PDF o imagen).
 */

return [
    'max_compras' => 6,
    'frecuencias' => ['quincenal', 'semanal'],
    'comprobante_kb' => 10240,
];
