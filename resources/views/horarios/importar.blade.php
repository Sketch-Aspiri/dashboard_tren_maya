@php
    /** @var array<string, mixed>|null $resumen */
    $meses = [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'];
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-horarios.titulo :titulo="__('Subir horario oficial')" :detalle="__('Excel con una hoja por estación (No. Empleado, Nombre, Fecha, Jornada…)')" />
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <x-horarios.nav activo="importar" />

            <x-insumos.avisos />

            @if ($resumen === null)
                <form method="POST" action="{{ route('horarios.importar.store') }}" enctype="multipart/form-data" class="ins-card ins-card-pad space-y-3">
                    @csrf
                    <div>
                        <label for="archivo" class="ins-etiqueta">{{ __('Horario oficial (.xlsx)') }}</label>
                        <input id="archivo" name="archivo" type="file" required accept=".xlsx" class="ins-campo mt-1">
                        <p class="mt-1 text-xs text-gray-500">{{ __('Primero verás una vista previa; nada se publica hasta que confirmes. Si ya hay un horario de ese mes, el nuevo lo reemplaza y los cambios ya autorizados se conservan.') }}</p>
                    </div>
                    <button type="submit" class="ins-btn-primario">{{ __('Ver vista previa') }}</button>
                </form>
            @else
                <section class="ins-card ins-card-pad space-y-4" aria-labelledby="vp-titulo">
                    <div>
                        <h3 id="vp-titulo" class="ins-titulo">{{ $meses[$resumen['mes']] }} {{ $resumen['anio'] }} · {{ number_format($resumen['turnos']) }} {{ __('turnos') }}</h3>
                        <p class="text-sm text-gray-600">{{ $resumen['archivo'] }}</p>
                    </div>

                    @if ($resumen['reemplaza_version'])
                        <p class="ins-aviso-alerta">{{ __('Ya hay un horario vigente de este mes (versión :v). Al publicar, este lo reemplaza y los cambios autorizados se vuelven a aplicar encima.', ['v' => $resumen['reemplaza_version']]) }}</p>
                    @endif

                    @if (! empty($resumen['recuperadas']))
                        <p class="ins-aviso-info">{{ trans_choice('A :n fila le faltaba el número de empleado y se completó con el de la misma persona en esa hoja.|A :n filas les faltaba el número de empleado y se completó con el de la misma persona en esa hoja.', $resumen['recuperadas'], ['n' => $resumen['recuperadas']]) }}</p>
                    @endif

                    @if (! empty($resumen['sin_estacion']))
                        <div class="ins-aviso-alerta">
                            <div>
                                <p class="font-semibold">{{ __('Filas sin estación reconocida (solo las verá la Zona):') }}</p>
                                <ul class="mt-1 list-disc pl-5">
                                    @foreach ($resumen['sin_estacion'] as $nombre => $n)
                                        <li>{{ $nombre }}: {{ $n }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    @endif

                    @if ($resumen['total_errores'] > 0)
                        <div class="ins-aviso-error">
                            <div>
                                <p class="font-semibold">{{ trans_choice(':n fila se omitirá:|:n filas se omitirán:', $resumen['total_errores'], ['n' => $resumen['total_errores']]) }}</p>
                                <ul class="mt-1 list-disc pl-5">
                                    @foreach ($resumen['errores'] as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    @endif

                    <div class="ins-tabla-marco">
                        <table class="ins-tabla">
                            <thead><tr><th scope="col">{{ __('Estación') }}</th><th scope="col" class="!text-right">{{ __('Personas') }}</th><th scope="col" class="!text-right">{{ __('Turnos') }}</th></tr></thead>
                            <tbody>
                                @foreach ($resumen['por_estacion'] as $estacion => $d)
                                    <tr><td class="fuerte">{{ $estacion }}</td><td class="num">{{ $d['personas'] }}</td><td class="num">{{ $d['turnos'] }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if (! empty($resumen['hojas_omitidas']))
                        <p class="text-xs text-gray-500">{{ __('Hojas que se ignoran (sin las columnas del horario):') }} {{ implode(', ', $resumen['hojas_omitidas']) }}</p>
                    @endif

                    <div class="flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('horarios.importar.aplicar') }}">
                            @csrf
                            <input type="hidden" name="token" value="{{ $token }}">
                            <button type="submit" class="ins-btn-primario">{{ __('Publicar y avisar a las estaciones') }}</button>
                        </form>
                        <form method="POST" action="{{ route('horarios.importar.descartar') }}">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="token" value="{{ $token }}">
                            <button type="submit" class="ins-btn-neutro">{{ __('Descartar') }}</button>
                        </form>
                    </div>
                </section>
            @endif

            @if ($periodos->isNotEmpty())
                <section class="space-y-2" aria-labelledby="hist-titulo">
                    <h3 id="hist-titulo" class="ins-titulo">{{ __('Horarios publicados') }}</h3>
                    <div class="ins-tabla-marco">
                        <table class="ins-tabla">
                            <thead><tr><th scope="col">{{ __('Mes') }}</th><th scope="col">{{ __('Versión') }}</th><th scope="col">{{ __('Estado') }}</th><th scope="col">{{ __('Archivo') }}</th><th scope="col">{{ __('Subió') }}</th><th scope="col">{{ __('Publicado') }}</th></tr></thead>
                            <tbody>
                                @foreach ($periodos as $p)
                                    <tr>
                                        <td class="fuerte">{{ $meses[$p->mes] }} {{ $p->anio }}</td>
                                        <td>{{ $p->version }}</td>
                                        <td><span class="{{ $p->estado === 'vigente' ? 'ins-chip-ok' : 'ins-chip-neutro' }}">{{ $p->estado === 'vigente' ? __('Vigente') : __('Reemplazado') }}</span></td>
                                        <td>{{ $p->archivo_nombre }}</td>
                                        <td>{{ $p->subidoPor?->name ?? '—' }}</td>
                                        <td>{{ $p->publicado_at?->setTimezone('America/Cancun')->format('d/m/Y H:i') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif
        </div>
    </div>
</x-app-layout>
