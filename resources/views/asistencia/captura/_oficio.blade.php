@php
    /** @var \App\Models\Estacion $estacion */
    /** @var \Carbon\CarbonImmutable $fecha */
    /** @var array{capturada: bool, documento: ?\App\Models\DocumentoAsistencia, desactualizado: bool, numero: string, firmante_cargo: string, firmante_nombre: string} $oficio */
    /** @var bool $puedeGenerarOficio */
    $documento = $oficio['documento'];
@endphp

<div class="overflow-hidden rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-6 space-y-4">
    <div>
        <h3 class="font-heading text-base font-semibold text-brand-green">{{ __('Oficio de asistencia') }}</h3>
        <p class="mt-1 text-sm text-gray-600">
            {{ __('Control de asistencia de la estación en Word y PDF, con los datos capturados de esta fecha.') }}
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
            @if ($oficio['desactualizado'])
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

    @if (! $puedeGenerarOficio)
        <p class="text-sm text-gray-500">{{ __('Solo puedes generar el oficio de hoy para tu estación.') }}</p>
    @elseif (! $oficio['capturada'])
        <p class="text-sm text-gray-600">
            {{ __('Guarda la asistencia de todo el personal de la estación para poder generar el oficio.') }}
        </p>
    @else
        <form method="POST" action="{{ route('asistencia.captura.oficio.store') }}" class="space-y-4 border-t border-brand-green/10 pt-4">
            @csrf
            <input type="hidden" name="fecha" value="{{ $fecha->toDateString() }}">
            @if (auth()->user()->hasRole('Administrador'))
                <input type="hidden" name="estacion" value="{{ $estacion->id }}">
            @endif

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="sm:col-span-3">
                    <x-input-label for="oficio-numero" :value="__('No. de oficio (Tjta. No.)')" />
                    <x-text-input id="oficio-numero" name="numero_oficio" class="block mt-1 w-full" maxlength="80" required
                                  :value="old('numero_oficio', $oficio['numero'])" />
                    <p class="mt-1 text-xs text-gray-500">{{ __('Sugerido por el sistema; corrígelo si el folio real es otro.') }}</p>
                    <x-input-error :messages="$errors->get('numero_oficio')" class="mt-1" />
                </div>
                <div class="sm:col-span-1">
                    <x-input-label for="oficio-cargo" :value="__('Cargo de quien firma')" />
                    <x-text-input id="oficio-cargo" name="firmante_cargo" class="block mt-1 w-full" maxlength="150" required
                                  :value="old('firmante_cargo', $oficio['firmante_cargo'])" />
                    <x-input-error :messages="$errors->get('firmante_cargo')" class="mt-1" />
                </div>
                <div class="sm:col-span-2">
                    <x-input-label for="oficio-firmante" :value="__('Nombre de quien firma')" />
                    <x-text-input id="oficio-firmante" name="firmante_nombre" class="block mt-1 w-full" maxlength="150" required
                                  :value="old('firmante_nombre', $oficio['firmante_nombre'])" />
                    <x-input-error :messages="$errors->get('firmante_nombre')" class="mt-1" />
                </div>
            </div>

            <div class="flex justify-end">
                <x-primary-button class="w-full sm:w-auto">
                    {{ $documento ? __('Regenerar oficio') : __('Generar oficio') }}
                </x-primary-button>
            </div>
            <p class="text-xs text-gray-500">{{ __('La generación puede tardar unos segundos (se convierte a PDF).') }}</p>
        </form>
    @endif
</div>
