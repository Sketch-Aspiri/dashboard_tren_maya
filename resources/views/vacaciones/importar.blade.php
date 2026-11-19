@php
    /** @var array<string, mixed>|null $resumen */
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-vacaciones.titulo :titulo="__('Subir rol de vacaciones')" :detalle="__('Excel con una hoja por estación (No. Empleado, Nombre, Días otorgados y cuatro trimestres)')" />
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <x-vacaciones.nav activo="importar" />

            <x-insumos.avisos />

            @if ($resumen === null)
                <form method="POST" action="{{ route('vacaciones.importar.store') }}" enctype="multipart/form-data" class="ins-card ins-card-pad space-y-3">
                    @csrf
                    <div>
                        <label for="archivo" class="ins-etiqueta">{{ __('Rol de vacaciones (.xlsx)') }}</label>
                        <input id="archivo" name="archivo" type="file" required accept=".xlsx" class="ins-campo mt-1">
                        @error('archivo') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                        <p class="mt-1 text-xs text-gray-500">{{ __('Primero verás una vista previa; nada se publica hasta que confirmes. Si ya hay un rol de ese año, el nuevo lo reemplaza y los cambios ya autorizados se conservan.') }}</p>
                    </div>
                    <button type="submit" class="ins-btn-primario">{{ __('Ver vista previa') }}</button>
                </form>
            @else
                <section class="ins-card ins-card-pad space-y-4" aria-labelledby="vp-titulo">
                    <div>
                        <h3 id="vp-titulo" class="ins-titulo">{{ $resumen['anio'] }} · {{ number_format($resumen['personas']) }} {{ __('personas') }}</h3>
                        <p class="text-sm text-gray-600">{{ $resumen['archivo'] }}</p>
                    </div>

                    @if ($resumen['reemplaza_version'])
                        <p class="ins-aviso-alerta">{{ __('Ya hay un rol vigente de este año (versión :v). Al publicar, este lo reemplaza, los cambios autorizados se conservan y las solicitudes pendientes se cancelan.', ['v' => $resumen['reemplaza_version']]) }}</p>
                    @endif

                    @if ($resumen['discrepancias'] > 0)
                        <p class="ins-aviso-info">{{ __(':n tramo(s) traen en el archivo un número de días distinto al de lunes a viernes; se respeta el del archivo.', ['n' => $resumen['discrepancias']]) }}</p>
                    @endif

                    @if ($resumen['excedidos'] > 0)
                        <p class="ins-aviso-alerta">{{ __(':n persona(s) tienen más días solicitados que otorgados.', ['n' => $resumen['excedidos']]) }}</p>
                    @endif

                    @if (! empty($resumen['sin_estacion']))
                        <div class="ins-aviso-alerta">
                            <div>
                                <p class="font-semibold">{{ __('Hojas sin estación reconocida (solo las verá la Zona):') }}</p>
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
                                <p class="font-semibold">{{ __(':n fila(s) se omitirán:', ['n' => $resumen['total_errores']]) }}</p>
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
                            <thead><tr><th scope="col">{{ __('Estación') }}</th><th scope="col" class="!text-right">{{ __('Personas') }}</th><th scope="col" class="!text-right">{{ __('Días programados') }}</th></tr></thead>
                            <tbody>
                                @foreach ($resumen['por_estacion'] as $estacion => $d)
                                    <tr><td class="fuerte">{{ $estacion }}</td><td class="num">{{ $d['personas'] }}</td><td class="num">{{ $d['dias'] }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if (! empty($resumen['hojas_omitidas']))
                        <p class="text-xs text-gray-500">{{ __('Hojas que se ignoran (consolidado o sin las columnas del rol):') }} {{ implode(', ', $resumen['hojas_omitidas']) }}</p>
                    @endif

                    <div class="flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('vacaciones.importar.aplicar') }}">
                            @csrf
                            <input type="hidden" name="token" value="{{ $token }}">
                            <button type="submit" class="ins-btn-primario">{{ __('Publicar y avisar a las estaciones') }}</button>
                        </form>
                        <form method="POST" action="{{ route('vacaciones.importar.descartar') }}">
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
                    <h3 id="hist-titulo" class="ins-titulo">{{ __('Roles publicados') }}</h3>
                    <div class="ins-tabla-marco">
                        <table class="ins-tabla">
                            <thead><tr><th scope="col">{{ __('Año') }}</th><th scope="col">{{ __('Versión') }}</th><th scope="col">{{ __('Estado') }}</th><th scope="col">{{ __('Archivo') }}</th><th scope="col">{{ __('Subió') }}</th><th scope="col">{{ __('Publicado') }}</th><th scope="col"><span class="sr-only">{{ __('Descargar') }}</span></th></tr></thead>
                            <tbody>
                                @foreach ($periodos as $p)
                                    <tr>
                                        <td class="fuerte">{{ $p->anio }}</td>
                                        <td>{{ $p->version }}</td>
                                        <td><span class="{{ $p->estado === 'vigente' ? 'ins-chip-ok' : 'ins-chip-neutro' }}">{{ $p->estado === 'vigente' ? __('Vigente') : __('Reemplazado') }}</span></td>
                                        <td>{{ $p->archivo_nombre }}</td>
                                        <td>{{ $p->subidoPor?->name ?? '—' }}</td>
                                        <td>{{ $p->publicado_at?->setTimezone('America/Cancun')->format('d/m/Y H:i') }}</td>
                                        <td><a href="{{ route('vacaciones.consolidado.descargar', $p) }}" class="text-sm font-semibold text-brand-green hover:underline">{{ __('Descargar Excel') }}</a></td>
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
