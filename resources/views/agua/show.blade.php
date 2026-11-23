@php
    /** @var \App\Models\AguaRegistro $registro */
    /** @var bool $puedeEditar */
    /** @var bool $puedeRevisar */
    /** @var bool $puedeProgramar */
    $nombresMes = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
        7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];
    $dinero = fn ($n) => '$'.number_format((float) $n, 2);
    $saldo = $registro->saldo();
    $inicioMes = \Carbon\CarbonImmutable::create($registro->anio, $registro->mes, 1);
    $hoy = now('America/Cancun')->toImmutable();
    $maxFecha = $hoy->lt($inicioMes->endOfMonth()) ? $hoy->toDateString() : $inicioMes->endOfMonth()->toDateString();
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-insumos.titulo :titulo="$registro->estacion->nombre.' — '.$nombresMes[$registro->mes].' '.$registro->anio"
                          :detalle="__('Registro de agua')" :seccion="__('Agua')"
                          :volver="$puedeEditar ? null : route('agua.index', ['anio' => $registro->anio, 'mes' => $registro->mes])"
                          :volver-texto="__('Registros del mes')" />
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <x-insumos.avisos />
            @error('compra') <p class="ins-aviso-error">{{ $message }}</p> @enderror

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
                @if ($registro->enviada_at)
                    <span>{{ __('Enviado') }}: <span class="font-medium text-gray-900">{{ $registro->enviada_at->setTimezone('America/Cancun')->format('d/m/Y H:i') }}</span></span>
                @endif
                @if ($registro->revisor)
                    <span>{{ __('Revisó') }}: <span class="font-medium text-gray-900">{{ $registro->revisor->name }}</span></span>
                @endif
            </div>

            {{-- Programa del mes --}}
            <section class="ins-card ins-card-pad space-y-3" aria-labelledby="programa-titulo">
                <h3 id="programa-titulo" class="ins-titulo">{{ __('Programa del mes') }}</h3>
                @if ($puedeProgramar)
                    <form method="POST" action="{{ route('agua.programa', $registro) }}" class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                        @csrf
                        @method('PUT')
                        <div>
                            <label for="frecuencia" class="ins-etiqueta">{{ __('Frecuencia de compra') }}</label>
                            <select id="frecuencia" name="frecuencia" class="ins-campo mt-1">
                                @foreach ($frecuencias as $f)
                                    <option value="{{ $f }}" @selected(old('frecuencia', $registro->frecuencia) === $f)>{{ ucfirst($f) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="consumo_mensual" class="ins-etiqueta">{{ __('Consumo mensual neto') }}</label>
                            <input id="consumo_mensual" name="consumo_mensual" type="number" min="0" max="2000" required class="ins-campo mt-1" value="{{ old('consumo_mensual', $registro->consumo_mensual) }}">
                            <x-input-error :messages="$errors->get('consumo_mensual')" class="mt-1" />
                        </div>
                        <div>
                            <label for="costo_unitario" class="ins-etiqueta">{{ __('Costo unitario') }}</label>
                            <input id="costo_unitario" name="costo_unitario" type="number" step="0.01" min="0" max="1000" required class="ins-campo mt-1" value="{{ old('costo_unitario', $registro->costo_unitario) }}">
                            <x-input-error :messages="$errors->get('costo_unitario')" class="mt-1" />
                        </div>
                        <div>
                            <label for="garrafones_propios" class="ins-etiqueta">{{ __('Garrafones propios') }}</label>
                            <input id="garrafones_propios" name="garrafones_propios" type="number" min="0" max="500" required class="ins-campo mt-1" value="{{ old('garrafones_propios', $registro->garrafones_propios) }}">
                        </div>
                        <div>
                            <label for="garrafones_comodato" class="ins-etiqueta">{{ __('Garrafones a comodato') }}</label>
                            <input id="garrafones_comodato" name="garrafones_comodato" type="number" min="0" max="500" required class="ins-campo mt-1" value="{{ old('garrafones_comodato', $registro->garrafones_comodato) }}">
                        </div>
                        <div>
                            <label for="referencia" class="ins-etiqueta">{{ __('Referencia') }}</label>
                            <input id="referencia" name="referencia" type="text" maxlength="60" class="ins-campo mt-1" value="{{ old('referencia', $registro->referencia) }}">
                        </div>
                        <div>
                            <label for="dia_entrega" class="ins-etiqueta">{{ __('Día de entrega') }}</label>
                            <input id="dia_entrega" name="dia_entrega" type="text" maxlength="60" class="ins-campo mt-1" value="{{ old('dia_entrega', $registro->dia_entrega) }}">
                        </div>
                        <div class="flex items-end">
                            <button type="submit" class="ins-btn-secundario w-full">{{ __('Guardar programa') }}</button>
                        </div>
                    </form>
                @else
                    <dl class="grid grid-cols-2 gap-3 text-sm lg:grid-cols-4">
                        <div><dt class="text-gray-500">{{ __('Frecuencia') }}</dt><dd class="font-medium text-gray-900">{{ ucfirst($registro->frecuencia) }}</dd></div>
                        <div><dt class="text-gray-500">{{ __('Consumo mensual neto') }}</dt><dd class="font-medium text-gray-900">{{ $registro->consumo_mensual }} {{ __('garrafones') }}</dd></div>
                        <div><dt class="text-gray-500">{{ __('Costo unitario') }}</dt><dd class="font-medium text-gray-900">{{ $dinero($registro->costo_unitario) }}</dd></div>
                        <div><dt class="text-gray-500">{{ __('Referencia') }}</dt><dd class="font-medium text-gray-900">{{ $registro->referencia ?: '—' }}</dd></div>
                        <div><dt class="text-gray-500">{{ __('Garrafones propios') }}</dt><dd class="font-medium text-gray-900">{{ $registro->garrafones_propios }}</dd></div>
                        <div><dt class="text-gray-500">{{ __('Garrafones a comodato') }}</dt><dd class="font-medium text-gray-900">{{ $registro->garrafones_comodato }}</dd></div>
                        <div><dt class="text-gray-500">{{ __('Día de entrega') }}</dt><dd class="font-medium text-gray-900">{{ $registro->dia_entrega ?: '—' }}</dd></div>
                    </dl>
                    @if ((float) $registro->costo_unitario <= 0)
                        <p class="ins-aviso-alerta">{{ __('La Zona aún no define el costo unitario de tu estación; no podrás registrar compras hasta entonces.') }}</p>
                    @endif
                @endif
            </section>

            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <x-kpi-card :label="__('Cuota asignada')" :value="$dinero($registro->cuota())" :hint="__('Consumo × costo unitario.')" />
                <x-kpi-card :label="__('Comprado')" :value="$dinero($registro->totalComprado())" :hint="trans_choice(':n garrafón|:n garrafones', $registro->garrafonesComprados(), ['n' => $registro->garrafonesComprados()])" />
                <x-kpi-card :label="$saldo > 0 ? __('Reintegro') : __('Pendiente de entrega')" :value="$dinero(abs($saldo))" :tono="$saldo > 0 ? 'alerta' : null"
                            :hint="$saldo > 0 ? __('Se compró más que la cuota.') : __('Falta por comprar de la cuota.')" />
                <x-kpi-card :label="__('Compras')" :value="$registro->compras->count().' / '.$maxCompras" :hint="__('Máximo por mes.')" />
            </div>

            {{-- Compras --}}
            <section class="space-y-3" aria-labelledby="compras-titulo">
                <h3 id="compras-titulo" class="ins-titulo">{{ __('Compras del mes') }}</h3>
                <div class="ins-tabla-marco">
                    <table class="ins-tabla">
                        <caption class="sr-only">{{ __('Compras de garrafones del mes') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Fecha') }}</th>
                                <th scope="col" class="!text-right">{{ __('Garrafones') }}</th>
                                <th scope="col" class="!text-right">{{ __('Monto') }}</th>
                                <th scope="col">{{ __('Comprobante') }}</th>
                                <th scope="col">{{ __('Nota') }}</th>
                                @if ($puedeEditar) <th scope="col" class="!text-right">{{ __('Acciones') }}</th> @endif
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($registro->compras as $compra)
                                <tr>
                                    <td class="whitespace-nowrap">{{ $compra->fecha?->format('d/m/Y') ?? '—' }}</td>
                                    <td class="num">{{ $compra->garrafones }}</td>
                                    <td class="num">{{ $dinero($compra->monto) }}</td>
                                    <td>
                                        @if ($compra->comprobante_ruta)
                                            <a href="{{ route('agua.comprobante', [$registro, $compra]) }}" target="_blank" rel="noopener" class="ins-enlace">{{ __('Ver comprobante') }}</a>
                                        @else
                                            <span class="text-gray-400">{{ $compra->origen === 'importacion' ? __('Importado') : '—' }}</span>
                                        @endif
                                    </td>
                                    <td class="text-gray-600">{{ $compra->nota ?: '—' }}</td>
                                    @if ($puedeEditar)
                                        <td class="num">
                                            <form method="POST" action="{{ route('agua.compras.destroy', [$registro, $compra]) }}" onsubmit="return confirm('{{ __('¿Quitar esta compra?') }}')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="ins-enlace-peligro !min-h-[36px]">{{ __('Quitar') }}</button>
                                            </form>
                                        </td>
                                    @endif
                                </tr>
                            @empty
                                <x-insumos.vacio :columnas="$puedeEditar ? 6 : 5" :titulo="__('Sin compras registradas')" />
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            @if ($puedeEditar)
                <section class="ins-card ins-card-pad space-y-3" aria-labelledby="nueva-titulo">
                    <h3 id="nueva-titulo" class="ins-titulo">{{ __('Registrar una compra') }}</h3>
                    <form method="POST" action="{{ route('agua.compras.store', $registro) }}" enctype="multipart/form-data" class="grid grid-cols-2 gap-3 lg:grid-cols-12">
                        @csrf
                        <div class="lg:col-span-3">
                            <label for="fecha" class="ins-etiqueta">{{ __('Fecha de compra') }}</label>
                            <input id="fecha" name="fecha" type="date" required class="ins-campo mt-1" min="{{ $inicioMes->toDateString() }}" max="{{ $maxFecha }}" value="{{ old('fecha', $maxFecha) }}">
                            <x-input-error :messages="$errors->get('fecha')" class="mt-1" />
                        </div>
                        <div class="lg:col-span-2">
                            <label for="garrafones" class="ins-etiqueta">{{ __('Garrafones') }}</label>
                            <input id="garrafones" name="garrafones" type="number" min="1" max="500" required class="ins-campo mt-1" value="{{ old('garrafones') }}">
                            <x-input-error :messages="$errors->get('garrafones')" class="mt-1" />
                        </div>
                        <div class="col-span-2 lg:col-span-7">
                            <label for="nota" class="ins-etiqueta">{{ __('Nota (opcional)') }}</label>
                            <input id="nota" name="nota" type="text" maxlength="255" class="ins-campo mt-1" value="{{ old('nota') }}">
                        </div>
                        <div class="col-span-2 lg:col-span-9">
                            <label for="comprobante" class="ins-etiqueta">{{ __('Comprobante (ticket o nota, PDF o imagen)') }}</label>
                            <input id="comprobante" name="comprobante" type="file" required accept=".pdf,.jpg,.jpeg,.png" class="ins-campo mt-1">
                            <x-input-error :messages="$errors->get('comprobante')" class="mt-1" />
                        </div>
                        <div class="col-span-2 flex items-end lg:col-span-3">
                            <button type="submit" class="ins-btn-primario w-full">{{ __('Agregar compra') }}</button>
                        </div>
                    </form>
                    <p class="ins-ayuda">{{ __('El monto se calcula solo: garrafones × costo unitario del mes.') }}</p>
                </section>

                @if ($registro->compras->isNotEmpty())
                    <form method="POST" action="{{ route('agua.enviar', $registro) }}" class="ins-barra-acciones">
                        @csrf
                        <p class="text-sm text-gray-500">{{ __('Cuando termines de registrar tus compras, envía el registro a la Zona.') }}</p>
                        <button type="submit" class="ins-btn-primario">{{ __('Enviar a la Zona') }}</button>
                    </form>
                @endif
            @endif

            @if ($puedeRevisar)
                <form method="POST" action="{{ route('agua.revisar', $registro) }}" class="space-y-6">
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
