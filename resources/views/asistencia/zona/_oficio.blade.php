@php
    /** @var \Carbon\CarbonImmutable $fecha */
    /** @var array{pendientes: \Illuminate\Support\Collection<int, \App\Models\Estacion>, documento: ?\App\Models\DocumentoAsistencia, desactualizado: bool, numero: string, firmante: string} $oficioZona */
    $documento = $oficioZona['documento'];
    $firmantes = config('asistencia_documentos.zona.firmantes');
@endphp

<div class="overflow-hidden rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-6 space-y-4">
    <div>
        <h3 class="font-heading text-base font-semibold text-brand-green">{{ __('Oficio de asistencia de la zona') }}</h3>
        <p class="mt-1 text-sm text-gray-600">
            {{ __('Control de asistencia diaria de toda la Dirección Zona Oriente en Word y PDF. Se habilita cuando todas las estaciones capturaron.') }}
        </p>
    </div>

    @if ($errors->has('oficio'))
        <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
            {{ $errors->first('oficio') }}
        </div>
    @endif

    @if ($documento)
        <div class="rounded-md border border-brand-green/20 bg-brand-mist px-4 py-3 text-sm text-brand-green-dark space-y-2">
            <p>
                {{ __('Generado') }}: <span class="font-medium">{{ $documento->updated_at->format('d/m/Y H:i') }}</span>
                — {{ __('No. de oficio') }}: <span class="font-medium">{{ $documento->numero_oficio }}</span>
            </p>
            @if ($oficioZona['desactualizado'])
                <p class="font-medium text-amber-700" role="status">
                    {{ __('La asistencia cambió después de generar este oficio. Regénéralo para actualizar los archivos.') }}
                </p>
            @endif
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('asistencia.documentos.download', [$documento, 'docx']) }}"
                   class="inline-flex min-h-[40px] items-center rounded-md border border-brand-green/30 bg-white px-4 py-2 text-xs font-semibold uppercase tracking-wide text-brand-green hover:bg-brand-mist">
                    {{ __('Descargar Word (.docx)') }}
                </a>
                <a href="{{ route('asistencia.documentos.download', [$documento, 'pdf']) }}"
                   class="inline-flex min-h-[40px] items-center rounded-md border border-brand-green/30 bg-white px-4 py-2 text-xs font-semibold uppercase tracking-wide text-brand-green hover:bg-brand-mist">
                    {{ __('Descargar PDF') }}
                </a>
            </div>
        </div>
    @endif

    @if ($oficioZona['pendientes']->isNotEmpty())
        <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800" role="status">
            <p class="font-medium">{{ __('Aún faltan por capturar:') }}</p>
            <p class="mt-1">{{ $oficioZona['pendientes']->pluck('nombre')->implode(', ') }}</p>
        </div>
    @else
        <form method="POST" action="{{ route('asistencia.zona.oficio.store') }}" class="space-y-4 border-t border-brand-green/10 pt-4">
            @csrf
            <input type="hidden" name="fecha" value="{{ $fecha->toDateString() }}">

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label for="zona-numero" :value="__('No. de oficio (Tjta. No.)')" />
                    <x-text-input id="zona-numero" name="numero_oficio" class="block mt-1 w-full" maxlength="80" required
                                  :value="old('numero_oficio', $oficioZona['numero'])" />
                    <p class="mt-1 text-xs text-gray-500">{{ __('El consecutivo se comparte con otros oficios de la Dirección: verifícalo antes de generar.') }}</p>
                    <x-input-error :messages="$errors->get('numero_oficio')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="zona-firmante" :value="__('Firma')" />
                    <select id="zona-firmante" name="firmante" required
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-teal focus:ring-brand-teal">
                        @foreach ($firmantes as $clave => $firmante)
                            <option value="{{ $clave }}" @selected(old('firmante', $oficioZona['firmante']) === $clave)>
                                {{ $firmante['etiqueta'] }}
                            </option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-gray-500">{{ __('Si firma el Subgerente se agrega el párrafo de suplencia.') }}</p>
                    <x-input-error :messages="$errors->get('firmante')" class="mt-1" />
                </div>
            </div>

            <div class="flex justify-end">
                <x-primary-button class="w-full sm:w-auto">
                    {{ $documento ? __('Regenerar oficio de zona') : __('Generar oficio de zona') }}
                </x-primary-button>
            </div>
            <p class="text-xs text-gray-500">
                {{ __('La generación puede tardar unos segundos. Los anexos escaneados (reportes de ausentismo, oficios de comisión) se agregan a mano al Word.') }}
            </p>
        </form>
    @endif
</div>
