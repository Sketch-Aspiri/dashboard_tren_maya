@php
    /** @var int $anio */
    /** @var int $mes */
    /** @var \Illuminate\Support\Collection<int, \App\Models\SolicitudInsumo> $solicitudes */
    /** @var int $aprobadas */
    /** @var array<int, string> $meses */
    $resumen = session('resumen_coordinacion');
    $campo = 'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-teal focus:ring-brand-teal text-sm';
    $listas = [
        'quitadas' => __('Renglones que la Coordinación quitó'),
        'agregadas' => __('Renglones que la Coordinación agregó'),
        'cambios' => __('Cantidades o precios que cambió'),
        'sin_solicitud' => __('Bloques del archivo sin solicitud enviada (no se tocaron)'),
        'sin_renglones' => __('Estaciones con bloque sin renglones (no se tocaron)'),
        'sin_bloque' => __('Estaciones enviadas que no aparecen en el archivo (no se tocaron)'),
    ];
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-heading text-xl font-semibold text-brand-green leading-tight">
            {{ __('Recursos materiales') }} — {{ __('Coordinación') }} {{ $meses[$mes] }} {{ $anio }}
        </h2>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="rounded-xl border border-brand-green/10 bg-brand-mist p-4 text-sm text-brand-green">{{ session('status') }}</div>
            @endif

            @if ($errors->any())
                <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                    <ul class="list-disc pl-5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-6">
                <form method="GET" action="{{ route('insumos.coordinacion.index') }}" class="flex flex-wrap items-end gap-2">
                    <div>
                        <x-input-label for="mes-coord" :value="__('Mes')" />
                        <select id="mes-coord" name="mes" class="{{ $campo }}">
                            @foreach ($meses as $numero => $nombre)
                                <option value="{{ $numero }}" @selected($numero === $mes)>{{ $nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="max-w-xs">
                        <x-input-label for="anio-coord" :value="__('Año')" />
                        <x-text-input id="anio-coord" name="anio" type="number" class="block mt-1 w-full" value="{{ $anio }}" min="2000" max="2100" />
                    </div>
                    <x-secondary-button type="submit">{{ __('Ver mes') }}</x-secondary-button>
                </form>
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                {{-- 1. Envío del ANEXO a la Coordinación. --}}
                <form method="POST" action="{{ route('insumos.coordinacion.enviar') }}"
                      class="rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-6">
                    @csrf
                    <input type="hidden" name="anio" value="{{ $anio }}">
                    <input type="hidden" name="mes" value="{{ $mes }}">
                    <h3 class="font-semibold text-brand-green">{{ __('1. Registrar envío del ANEXO') }}</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        {{ __('Descarga el ANEXO desde el tablero, envíalo con tu oficio y registra aquí el número. Las :n solicitud(es) aprobada(s) de este mes pasan a "Enviada a Coordinación".', ['n' => $aprobadas]) }}
                    </p>
                    <div class="mt-4">
                        <x-input-label for="oficio-coord" :value="__('Número de oficio')" />
                        <x-text-input id="oficio-coord" name="oficio" type="text" class="block mt-1 w-full" maxlength="20" required pattern="[A-Za-z0-9_\-]+" placeholder="1918" />
                    </div>
                    <div class="mt-4 flex justify-end">
                        <x-primary-button type="submit" :disabled="$aprobadas === 0">{{ __('Registrar envío') }}</x-primary-button>
                    </div>
                </form>

                {{-- 2. Respuesta de la Coordinación (Excel devuelto). --}}
                <form method="POST" action="{{ route('insumos.coordinacion.respuesta') }}" enctype="multipart/form-data"
                      class="rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-6">
                    @csrf
                    <input type="hidden" name="anio" value="{{ $anio }}">
                    <input type="hidden" name="mes" value="{{ $mes }}">
                    <h3 class="font-semibold text-brand-green">{{ __('2. Cargar la respuesta de la Coordinación') }}</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        {{ __('Sube el Excel devuelto (.xlsx). Se toma la partida de la columna P.Ptal/Fin, los renglones que quitó o agregó y los cambios de cantidad o precio. Puedes volver a cargarlo si hay una versión corregida.') }}
                    </p>
                    <div class="mt-4">
                        <x-input-label for="archivo-coord" :value="__('Archivo')" />
                        <input id="archivo-coord" name="archivo" type="file" required accept=".xlsx" class="mt-1 block w-full text-sm text-gray-700">
                    </div>
                    <div class="mt-4 flex justify-end">
                        <x-secondary-button type="submit">{{ __('Aplicar respuesta') }}</x-secondary-button>
                    </div>
                </form>
            </div>

            @if ($resumen)
                <div class="rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-6 space-y-4">
                    <h3 class="font-semibold text-brand-green">{{ __('Resultado de la última carga') }}</h3>
                    <p class="text-sm text-gray-700">
                        {{ __(':s solicitud(es) actualizadas, :r renglones confirmados.', ['s' => $resumen['solicitudes'], 'r' => $resumen['actualizadas']]) }}
                    </p>
                    @foreach ($listas as $clave => $titulo)
                        @if (! empty($resumen[$clave]))
                            <div>
                                <p class="text-sm font-medium text-gray-800">{{ $titulo }} ({{ count($resumen[$clave]) }})</p>
                                <ul class="mt-1 list-disc pl-5 text-sm text-gray-600">
                                    @foreach ($resumen[$clave] as $linea)
                                        <li>{{ $linea }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif

            <div class="overflow-x-auto rounded-xl border border-brand-green/10 bg-white shadow-sm shadow-brand-green/5">
                <table class="min-w-full text-sm">
                    <thead class="bg-brand-mist text-left text-xs font-semibold uppercase tracking-wide text-brand-green">
                        <tr>
                            <th class="px-4 py-3">{{ __('Estación') }}</th>
                            <th class="px-4 py-3">{{ __('Estado') }}</th>
                            <th class="px-4 py-3">{{ __('Oficio') }}</th>
                            <th class="px-4 py-3">{{ __('Enviada a Coordinación') }}</th>
                            <th class="px-4 py-3">{{ __('Devuelta') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($solicitudes as $solicitud)
                            <tr>
                                <td class="px-4 py-2 font-medium text-gray-900">{{ $solicitud->estacion->nombre }}</td>
                                <td class="px-4 py-2">{{ __($solicitud->etiquetaEstado()) }}</td>
                                <td class="px-4 py-2">{{ $solicitud->oficio_numero ?? '—' }}</td>
                                <td class="px-4 py-2">{{ $solicitud->enviada_coordinacion_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                <td class="px-4 py-2">{{ $solicitud->devuelta_at?->format('d/m/Y H:i') ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-8 text-center text-gray-500">{{ __('No hay solicitudes de este mes.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div>
                <a href="{{ route('insumos.index', ['anio' => $anio, 'mes' => $mes]) }}" class="text-sm text-gray-600 hover:text-brand-green">← {{ __('Volver') }}</a>
            </div>
        </div>
    </div>
</x-app-layout>
