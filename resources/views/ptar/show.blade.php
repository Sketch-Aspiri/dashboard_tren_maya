@php
    /** @var \App\Models\PtarRegistro $registro */
    /** @var string $semaforo */
    /** @var bool $puedeEditar */
    /** @var bool $puedeRevisar */
    /** @var int $maxEvidencias */
    $nombresMes = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
        7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];
    $hoy = now('America/Cancun')->toDateString();
    $modos = [
        'automatico' => __('Automático'), 'manual' => __('Manual'),
        'fuera_servicio' => __('Fuera de servicio'), 'sin_dato' => __('Sin dato'),
    ];
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-insumos.titulo :titulo="$registro->estacion->nombre.' — '.$nombresMes[$registro->mes].' '.$registro->anio"
                          :detalle="__('PTAR')" :seccion="__('PTAR')" :grupo="__('Otros')"
                          :volver="$puedeEditar ? null : route('ptar.index', ['anio' => $registro->anio, 'mes' => $registro->mes])"
                          :volver-texto="__('PTAR del mes')" />
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <x-insumos.avisos />
            @error('envio') <p class="ins-aviso-error">{{ $message }}</p> @enderror
            @error('foto') <p class="ins-aviso-error">{{ $message }}</p> @enderror

            @if ($registro->estado === 'observada' && $registro->comentario_zona)
                <div class="ins-aviso-alerta" role="status">
                    <span aria-hidden="true" class="mt-0.5 font-bold">!</span>
                    <div>
                        <p class="font-semibold">{{ __('La Zona devolvió el registro con este comentario:') }}</p>
                        <p class="mt-1 whitespace-pre-line">{{ $registro->comentario_zona }}</p>
                    </div>
                </div>
            @endif

            <div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-gray-600">
                <x-insumos.estado :valor="$registro->estado" :texto="__($registro->etiquetaEstado())" />
                @php
                    $chip = ['verde' => ['ins-chip-ok', __('Operación normal')], 'ambar' => ['ins-chip-alerta', __('Con atención')], 'rojo' => ['ins-chip-error', __('Con fallas')], 'gris' => ['ins-chip-neutro', __('Sin dato')]][$semaforo];
                @endphp
                <span class="{{ $chip[0] }}">{{ $chip[1] }}</span>
                @if ($registro->enviada_at)
                    <span>{{ __('Enviado') }}: <span class="font-medium text-gray-900">{{ $registro->enviada_at->setTimezone('America/Cancun')->format('d/m/Y H:i') }}</span></span>
                @endif
                <a href="{{ route('ptar.hallazgos.index', ['planta' => $registro->planta?->id]) }}" class="ins-enlace">{{ __('Hallazgos de esta planta') }}</a>
                @if ($registro->revisor)
                    <span>{{ __('Revisó') }}: <span class="font-medium text-gray-900">{{ $registro->revisor->name }}</span></span>
                @endif
            </div>

            @if ($puedeEditar)
                <form method="POST" action="{{ route('ptar.guardar', $registro) }}" class="space-y-6">
                    @csrf
                    @method('PUT')
            @endif

            {{-- Operación --}}
            <section class="ins-card ins-card-pad space-y-3" aria-labelledby="operacion-titulo">
                <h3 id="operacion-titulo" class="ins-titulo">{{ __('Operación de la planta') }}</h3>
                @if ($puedeEditar)
                    <div class="grid grid-cols-1 gap-3 lg:grid-cols-3">
                        <div>
                            <label for="modo" class="ins-etiqueta">{{ __('Modo de operación') }}</label>
                            <select id="modo" name="modo" class="ins-campo mt-1">
                                @foreach ($modos as $valor => $texto)
                                    <option value="{{ $valor }}" @selected(old('modo', $registro->modo) === $valor)>{{ $texto }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('modo')" class="mt-1" />
                        </div>
                        <div class="lg:col-span-2">
                            <label for="modo_detalle" class="ins-etiqueta">{{ __('Detalle (equipos que operan en manual o fuera de servicio)') }}</label>
                            <input id="modo_detalle" name="modo_detalle" type="text" maxlength="255" class="ins-campo mt-1" value="{{ old('modo_detalle', $registro->modo_detalle) }}">
                        </div>
                        <div class="lg:col-span-3">
                            <label for="operador" class="ins-etiqueta">{{ __('Operador (nombre y empresa)') }}</label>
                            <input id="operador" name="operador" type="text" maxlength="160" class="ins-campo mt-1" value="{{ old('operador', $registro->operador) }}">
                        </div>
                    </div>
                @else
                    <dl class="grid grid-cols-1 gap-3 text-sm lg:grid-cols-3">
                        <div><dt class="text-gray-500">{{ __('Modo de operación') }}</dt><dd class="font-medium text-gray-900">{{ $registro->etiquetaModo() }}</dd></div>
                        <div class="lg:col-span-2"><dt class="text-gray-500">{{ __('Detalle') }}</dt><dd class="font-medium text-gray-900">{{ $registro->modo_detalle ?: '—' }}</dd></div>
                        <div class="lg:col-span-3"><dt class="text-gray-500">{{ __('Operador') }}</dt><dd class="font-medium text-gray-900">{{ $registro->operador ?: '—' }}</dd></div>
                    </dl>
                @endif
            </section>

            {{-- Equipos --}}
            <section class="space-y-3" aria-labelledby="equipos-titulo">
                <h3 id="equipos-titulo" class="ins-titulo">{{ __('Equipo eléctrico') }}</h3>
                <div class="ins-tabla-marco">
                    <table class="ins-tabla">
                        <caption class="sr-only">{{ __('Estatus de los equipos de la PTAR') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Equipo') }}</th>
                                <th scope="col">{{ __('Estatus') }}</th>
                                <th scope="col">{{ __('Observaciones') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($registro->equipos as $equipo)
                                <tr>
                                    <th scope="row">{{ $equipo->etiquetaEquipo() }}</th>
                                    @if ($puedeEditar)
                                        <td>
                                            <label class="sr-only" for="eq-{{ $equipo->equipo }}">{{ __('Estatus de :e', ['e' => $equipo->etiquetaEquipo()]) }}</label>
                                            <select id="eq-{{ $equipo->equipo }}" name="equipos[{{ $equipo->equipo }}][estatus]" class="ins-campo">
                                                @foreach (\App\Models\PtarEquipo::ETIQUETAS as $valor => $texto)
                                                    <option value="{{ $valor }}" @selected(old('equipos.'.$equipo->equipo.'.estatus', $equipo->estatus) === $valor)>{{ __($texto) }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td>
                                            <label class="sr-only" for="eqo-{{ $equipo->equipo }}">{{ __('Observaciones de :e', ['e' => $equipo->etiquetaEquipo()]) }}</label>
                                            <textarea id="eqo-{{ $equipo->equipo }}" name="equipos[{{ $equipo->equipo }}][observaciones]" rows="2" maxlength="2000" class="ins-campo">{{ old('equipos.'.$equipo->equipo.'.observaciones', $equipo->observaciones) }}</textarea>
                                        </td>
                                    @else
                                        <td>
                                            <span class="{{ ['operando' => 'ins-chip-ok', 'parcial' => 'ins-chip-alerta', 'fuera_servicio' => 'ins-chip-error', 'sin_dato' => 'ins-chip-neutro'][$equipo->estatus] ?? 'ins-chip-neutro' }}">{{ __($equipo->etiquetaEstatus()) }}</span>
                                        </td>
                                        <td class="whitespace-pre-line text-gray-600">{{ $equipo->observaciones ?: '—' }}</td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($puedeEditar)
                    <label for="observaciones_equipos" class="ins-etiqueta">{{ __('Observaciones del equipo eléctrico (en general)') }}</label>
                    <textarea id="observaciones_equipos" name="observaciones_equipos" rows="4" maxlength="5000" class="ins-campo">{{ old('observaciones_equipos', $registro->observaciones_equipos) }}</textarea>
                    <x-input-error :messages="$errors->get('observaciones_equipos')" class="mt-1" />
                @elseif ($registro->observaciones_equipos)
                    <p class="whitespace-pre-line text-sm text-gray-700">{{ $registro->observaciones_equipos }}</p>
                @endif
            </section>

            {{-- Actividades de mantenimiento --}}
            <section class="space-y-3" aria-labelledby="actividades-titulo">
                <h3 id="actividades-titulo" class="ins-titulo">{{ __('Actividades de mantenimiento') }}</h3>
                <div class="ins-tabla-marco">
                    <table class="ins-tabla">
                        <caption class="sr-only">{{ __('Última fecha de mantenimiento por actividad') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Actividad') }}</th>
                                <th scope="col">{{ __('Última fecha de mantenimiento') }}</th>
                                <th scope="col">{{ __('Nota') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($registro->actividades as $actividad)
                                <tr>
                                    <th scope="row">{{ $actividad->actividad }}</th>
                                    @if ($puedeEditar)
                                        <td>
                                            <label class="sr-only" for="ac-{{ $actividad->id }}">{{ __('Fecha de :a', ['a' => $actividad->actividad]) }}</label>
                                            <input id="ac-{{ $actividad->id }}" name="actividades[{{ $actividad->id }}][ultima_fecha]" type="date" max="{{ $hoy }}" class="ins-campo" value="{{ old('actividades.'.$actividad->id.'.ultima_fecha', $actividad->ultima_fecha?->toDateString()) }}">
                                            <x-input-error :messages="$errors->get('actividades.'.$actividad->id.'.ultima_fecha')" class="mt-1" />
                                        </td>
                                        <td>
                                            <label class="sr-only" for="acn-{{ $actividad->id }}">{{ __('Nota de :a', ['a' => $actividad->actividad]) }}</label>
                                            <input id="acn-{{ $actividad->id }}" name="actividades[{{ $actividad->id }}][nota]" type="text" maxlength="255" class="ins-campo" value="{{ old('actividades.'.$actividad->id.'.nota', $actividad->nota) }}">
                                        </td>
                                    @else
                                        <td class="whitespace-nowrap">
                                            {{ $actividad->ultima_fecha?->format('d/m/Y') ?? '—' }}
                                            @if ($actividad->estaVencida()) <span class="ins-chip-alerta ml-1">{{ __('Vencido') }}</span> @endif
                                        </td>
                                        <td class="text-gray-600">{{ $actividad->nota ?: '—' }}</td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            {{-- Observaciones generales --}}
            <section class="ins-card ins-card-pad space-y-2" aria-labelledby="obs-titulo">
                <h3 id="obs-titulo" class="ins-titulo">{{ __('Observaciones generales del mes') }}</h3>
                @if ($puedeEditar)
                    <label for="observaciones_generales" class="sr-only">{{ __('Observaciones generales del mes') }}</label>
                    <textarea id="observaciones_generales" name="observaciones_generales" rows="6" maxlength="5000" class="ins-campo">{{ old('observaciones_generales', $registro->observaciones_generales) }}</textarea>
                    <x-input-error :messages="$errors->get('observaciones_generales')" class="mt-1" />
                @else
                    <p class="whitespace-pre-line text-sm text-gray-700">{{ $registro->observaciones_generales ?: '—' }}</p>
                @endif
            </section>

            @if ($puedeEditar)
                    <div class="ins-barra-acciones">
                        <p class="text-sm text-gray-500">{{ __('Guarda la captura antes de subir fotos o enviar.') }}</p>
                        <button type="submit" class="ins-btn-secundario">{{ __('Guardar captura') }}</button>
                    </div>
                </form>
            @endif

            {{-- Evidencia fotográfica --}}
            <section class="space-y-3" aria-labelledby="fotos-titulo">
                <h3 id="fotos-titulo" class="ins-titulo">{{ __('Evidencia fotográfica') }} ({{ $registro->evidencias->count() }} / {{ $maxEvidencias }})</h3>
                @if ($registro->evidencias->isEmpty())
                    <p class="text-sm text-gray-500">{{ __('Aún no hay fotos.') }}</p>
                @else
                    <ul class="grid grid-cols-2 gap-4 lg:grid-cols-3">
                        @foreach ($registro->evidencias as $foto)
                            <li class="ins-card overflow-hidden">
                                <a href="{{ route('ptar.evidencia', [$registro, $foto]) }}" target="_blank" rel="noopener">
                                    <img src="{{ route('ptar.evidencia', [$registro, $foto]) }}" alt="{{ $foto->pie ?: $foto->nombre }}" loading="lazy" class="h-40 w-full object-cover">
                                </a>
                                <div class="flex items-center justify-between gap-2 p-2 text-sm">
                                    <span class="truncate text-gray-600">{{ $foto->pie ?: $foto->nombre }}</span>
                                    @if ($puedeEditar)
                                        <form method="POST" action="{{ route('ptar.evidencias.destroy', [$registro, $foto]) }}" onsubmit="return confirm('{{ __('¿Quitar esta foto?') }}')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="ins-enlace-peligro !min-h-[36px]">{{ __('Quitar') }}</button>
                                        </form>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($puedeEditar && $registro->evidencias->count() < $maxEvidencias)
                    <form method="POST" action="{{ route('ptar.evidencias.store', $registro) }}" enctype="multipart/form-data" class="ins-card ins-card-pad grid grid-cols-2 gap-3 lg:grid-cols-12">
                        @csrf
                        <div class="col-span-2 lg:col-span-5">
                            <label for="foto" class="ins-etiqueta">{{ __('Foto (JPG o PNG)') }}</label>
                            <input id="foto" name="foto" type="file" required accept=".jpg,.jpeg,.png" class="ins-campo mt-1">
                            <x-input-error :messages="$errors->get('foto')" class="mt-1" />
                        </div>
                        <div class="col-span-2 lg:col-span-5">
                            <label for="pie" class="ins-etiqueta">{{ __('Pie de foto (opcional)') }}</label>
                            <input id="pie" name="pie" type="text" maxlength="255" class="ins-campo mt-1" value="{{ old('pie') }}">
                        </div>
                        <div class="col-span-2 flex items-end lg:col-span-2">
                            <button type="submit" class="ins-btn-secundario w-full">{{ __('Subir foto') }}</button>
                        </div>
                    </form>
                @endif
            </section>

            @if ($puedeEditar)
                <form method="POST" action="{{ route('ptar.enviar', $registro) }}" class="ins-barra-acciones">
                    @csrf
                    <p class="text-sm text-gray-500">{{ __('Cuando la captura y las fotos estén listas, envía el registro a la Zona.') }}</p>
                    <button type="submit" class="ins-btn-primario">{{ __('Enviar a la Zona') }}</button>
                </form>
            @endif

            @if ($puedeRevisar)
                <form method="POST" action="{{ route('ptar.revisar', $registro) }}" class="space-y-6">
                    @csrf
                    <section class="ins-card ins-card-pad">
                        <x-input-label for="comentario_zona" :value="__('Comentario para la estación (obligatorio al devolver)')" />
                        <textarea id="comentario_zona" name="comentario_zona" rows="3" class="ins-campo mt-1" placeholder="{{ __('Qué debe corregir la estación…') }}">{{ old('comentario_zona') }}</textarea>
                        <x-input-error :messages="$errors->get('comentario_zona')" class="mt-1" />
                    </section>
                    <div class="ins-barra-acciones">
                        <p class="text-sm text-gray-500">{{ __('Al aprobar, el registro del mes queda cerrado.') }}</p>
                        <div class="flex flex-col-reverse gap-2 sm:flex-row">
                            <button type="submit" name="accion" value="devolver" class="ins-btn-peligro">{{ __('Devolver a la estación') }}</button>
                            <button type="submit" name="accion" value="aprobar" class="ins-btn-primario">{{ __('Aprobar') }}</button>
                        </div>
                    </div>
                </form>
            @endif
        </div>
    </div>
</x-app-layout>
