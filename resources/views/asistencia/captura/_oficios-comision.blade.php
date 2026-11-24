@php
    /** @var \App\Models\Estacion $estacion */
    /** @var \Carbon\CarbonImmutable $fecha */
    /** @var array{oficios_comision: \Illuminate\Support\Collection<int, \App\Models\OficioComision>} $oficio */
    $oficiosComision = $oficio['oficios_comision'];
    $esZona = auth()->user()->can('captureAnyEstacion', \App\Models\RegistroDiario::class);
@endphp

@can('gestionarDe', [\App\Models\OficioComision::class, $estacion])
    <div class="space-y-3 rounded-md border border-brand-green/15 p-4">
        <div>
            <h4 class="text-sm font-semibold text-brand-green">{{ __('Oficios de comisión') }}</h4>
            <p class="mt-1 text-xs text-gray-600">
                {{ __('Sube los oficios (PDF, Word o imagen). Se agregan en hojas al final del Word y del PDF de la estación y del control de la zona, mientras estén vigentes. Después de subir o quitar uno, regenera el oficio.') }}
            </p>
        </div>

        @foreach (['oficios_comision', 'archivos'] as $campo)
            @if ($errors->has($campo))
                <p class="text-sm text-red-700" role="alert">{{ $errors->first($campo) }}</p>
            @endif
        @endforeach
        @foreach ($errors->get('archivos.*') as $mensajes)
            <p class="text-sm text-red-700" role="alert">{{ $mensajes[0] }}</p>
        @endforeach

        @if ($oficiosComision->isNotEmpty())
            <ul class="divide-y divide-brand-green/10 rounded-md border border-brand-green/10 text-sm">
                @foreach ($oficiosComision as $item)
                    <li class="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                        <div class="min-w-0">
                            <a href="{{ route('asistencia.oficios-comision.download', $item) }}" class="break-all font-medium text-brand-green hover:underline">{{ $item->nombre_original }}</a>
                            <p class="text-xs text-gray-500">
                                @if ($item->vigente_hasta === null)
                                    {{ __('Vigente hasta que se quite') }}
                                @elseif ($item->vigenteEn($fecha))
                                    {{ __('Vigente hasta :fecha', ['fecha' => $item->vigente_hasta->format('d/m/Y')]) }}
                                @else
                                    <span class="text-amber-700">{{ __('Vencido el :fecha (ya no se anexa)', ['fecha' => $item->vigente_hasta->format('d/m/Y')]) }}</span>
                                @endif
                            </p>
                        </div>
                        <form method="POST" action="{{ route('asistencia.oficios-comision.destroy', $item) }}" onsubmit="return confirm('{{ __('¿Quitar este oficio?') }}')">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="fecha" value="{{ $fecha->toDateString() }}">
                            <button type="submit" class="text-xs font-semibold uppercase tracking-wide text-red-700 hover:underline">{{ __('Quitar') }}</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @else
            <p class="text-sm text-gray-500">{{ __('Esta estación no tiene oficios de comisión cargados.') }}</p>
        @endif

        <form method="POST" action="{{ route('asistencia.captura.oficios-comision.store') }}" enctype="multipart/form-data" class="grid gap-3 sm:grid-cols-3">
            @csrf
            <input type="hidden" name="fecha" value="{{ $fecha->toDateString() }}">
            @if ($esZona)
                <input type="hidden" name="estacion" value="{{ $estacion->id }}">
            @endif
            <div class="sm:col-span-2">
                <x-input-label for="oficios-archivos" :value="__('Archivos (PDF, Word o imagen; hasta 10)')" />
                <input id="oficios-archivos" type="file" name="archivos[]" multiple required accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                       class="mt-1 block w-full text-sm text-gray-700 file:mr-3 file:rounded-md file:border-0 file:bg-brand-mist file:px-3 file:py-2 file:text-xs file:font-semibold file:uppercase file:text-brand-green hover:file:bg-brand-green/10">
            </div>
            <div>
                <x-input-label for="oficios-vigencia" :value="__('Vigente hasta (opcional)')" />
                <x-text-input id="oficios-vigencia" type="date" name="vigente_hasta" class="mt-1 block w-full" />
            </div>
            <div class="sm:col-span-3 flex justify-end">
                <button type="submit" class="inline-flex min-h-[40px] items-center rounded-md border border-brand-green/30 bg-white px-4 py-2 text-xs font-semibold uppercase tracking-wide text-brand-green hover:bg-brand-mist">
                    {{ __('Subir oficios') }}
                </button>
            </div>
        </form>
    </div>
@endcan
