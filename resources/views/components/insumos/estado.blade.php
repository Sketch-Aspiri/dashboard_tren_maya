@props(['valor', 'texto' => null])

{{-- Etiqueta de estado con el mismo color en todo el módulo: estados de la
     solicitud, de la factura (OK/ALERTA/REVISAR/ERROR), del SAT y de los
     renglones de cotización. --}}
@php
    $mapa = [
        // Solicitud
        'sin_iniciar' => ['error', 'Sin iniciar'],
        'borrador' => ['neutro', 'Borrador'],
        'enviada' => ['info', 'Enviada'],
        'observada' => ['alerta', 'Observada'],
        'aprobada' => ['ok', 'Aprobada'],
        'enviada_coordinacion' => ['marca', 'Enviada a Coordinación'],
        'devuelta' => ['morado', 'Devuelta por Coordinación'],
        // Factura y hallazgos
        'OK' => ['ok', 'OK'],
        'ALERTA' => ['alerta', 'ALERTA'],
        'REVISAR' => ['info', 'REVISAR'],
        'ERROR' => ['error', 'ERROR'],
        // SAT
        'VIGENTE' => ['ok', 'VIGENTE'],
        'CANCELADO' => ['error', 'CANCELADO'],
        'NO_ENCONTRADO' => ['info', 'NO_ENCONTRADO'],
        'NO_VERIFICADO' => ['neutro', 'NO_VERIFICADO'],
        // Renglón de cotización
        'actualizado' => ['ok', 'Actualizado'],
        'revisar' => ['alerta', 'Revisar'],
        'sin_vincular' => ['neutro', 'Fuera del documento'],
        // Inventario
        'HERRAMIENTA' => ['info', 'Herramienta'],
        'EPP' => ['ok', 'Equipo de protección'],
        'CONSUMIBLE' => ['neutro', 'Consumible entregado'],
    ];
    [$tono, $porDefecto] = $mapa[$valor] ?? ['neutro', $valor];
    // Nombres completos para que Tailwind los conserve al compilar.
    $clase = [
        'ok' => 'ins-chip-ok', 'alerta' => 'ins-chip-alerta', 'error' => 'ins-chip-error', 'info' => 'ins-chip-info',
        'morado' => 'ins-chip-morado', 'marca' => 'ins-chip-marca', 'neutro' => 'ins-chip-neutro',
    ][$tono];
@endphp

<span {{ $attributes->merge(['class' => $clase]) }}>{{ $texto ?? __($porDefecto) }}</span>
