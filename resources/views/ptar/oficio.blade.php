@php
    /** @var \Illuminate\Support\Collection<int, array{estacion: string, problematicas: list<string>, propuestas: list<string>}> $secciones */
    /** @var array<string, string> $valores */
    $v = fn (string $campo) => old($campo, $valores[$campo] ?? '');
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-insumos.titulo :titulo="__('Oficio mensual de PTAR')" :seccion="__('PTAR')" :grupo="__('Otros')"
                          :detalle="__('Word con las problemáticas y propuestas abiertas')"
                          :volver="route('ptar.hallazgos.index')" :volver-texto="__('Hallazgos')" />
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <section class="ins-card ins-card-pad space-y-2" aria-labelledby="incluye-titulo">
                <h3 id="incluye-titulo" class="ins-titulo">{{ __('Lo que incluirá el oficio') }}</h3>
                @forelse ($secciones as $s)
                    <p class="text-sm text-gray-700"><span class="font-semibold">{{ $s['estacion'] }}:</span>
                        {{ trans_choice(':n problemática|:n problemáticas', count($s['problematicas']), ['n' => count($s['problematicas'])]) }},
                        {{ trans_choice(':n propuesta|:n propuestas', count($s['propuestas']), ['n' => count($s['propuestas'])]) }}</p>
                @empty
                    <p class="text-sm text-gray-500">{{ __('No hay hallazgos sin cerrar; el oficio saldría sin plantas.') }}</p>
                @endforelse
                <p class="ins-ayuda">{{ __('Se toman los hallazgos abiertos y en proceso. Ciérralos o edítalos en Hallazgos antes de generar.') }}</p>
            </section>

            <form method="POST" action="{{ route('ptar.oficio.store') }}" class="ins-card ins-card-pad space-y-4">
                @csrf
                <div>
                    <label for="leyenda" class="ins-etiqueta">{{ __('Leyenda del año') }}</label>
                    <input id="leyenda" name="leyenda" type="text" maxlength="200" required class="ins-campo mt-1" value="{{ $v('leyenda') }}">
                    <x-input-error :messages="$errors->get('leyenda')" class="mt-1" />
                </div>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="numero" class="ins-etiqueta">{{ __('Número de oficio') }}</label>
                        <input id="numero" name="numero" type="text" maxlength="80" required class="ins-campo mt-1" value="{{ $v('numero') }}">
                        <x-input-error :messages="$errors->get('numero')" class="mt-1" />
                    </div>
                    <div>
                        <label for="fecha" class="ins-etiqueta">{{ __('Fecha') }}</label>
                        <input id="fecha" name="fecha" type="date" required class="ins-campo mt-1" value="{{ $v('fecha') }}">
                        <x-input-error :messages="$errors->get('fecha')" class="mt-1" />
                    </div>
                </div>
                <div>
                    <label for="destinatario" class="ins-etiqueta">{{ __('Para atención del (cargo y nombre)') }}</label>
                    <input id="destinatario" name="destinatario" type="text" maxlength="300" required class="ins-campo mt-1" value="{{ $v('destinatario') }}">
                    <x-input-error :messages="$errors->get('destinatario')" class="mt-1" />
                </div>
                <div>
                    <label for="asunto" class="ins-etiqueta">{{ __('Asunto') }}</label>
                    <textarea id="asunto" name="asunto" rows="3" maxlength="1000" required class="ins-campo mt-1">{{ $v('asunto') }}</textarea>
                    <x-input-error :messages="$errors->get('asunto')" class="mt-1" />
                </div>
                <div>
                    <label for="consideraciones" class="ins-etiqueta">{{ __('Consideración (un párrafo por bloque, separados por una línea en blanco)') }}</label>
                    <textarea id="consideraciones" name="consideraciones" rows="12" maxlength="8000" class="ins-campo mt-1">{{ $v('consideraciones') }}</textarea>
                    <x-input-error :messages="$errors->get('consideraciones')" class="mt-1" />
                </div>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="firmante_cargo" class="ins-etiqueta">{{ __('Cargo de quien firma') }}</label>
                        <input id="firmante_cargo" name="firmante_cargo" type="text" maxlength="200" required class="ins-campo mt-1" value="{{ $v('firmante_cargo') }}">
                        <x-input-error :messages="$errors->get('firmante_cargo')" class="mt-1" />
                    </div>
                    <div>
                        <label for="firmante_nombre" class="ins-etiqueta">{{ __('Nombre de quien firma') }}</label>
                        <input id="firmante_nombre" name="firmante_nombre" type="text" maxlength="200" required class="ins-campo mt-1" value="{{ $v('firmante_nombre') }}">
                        <x-input-error :messages="$errors->get('firmante_nombre')" class="mt-1" />
                    </div>
                </div>
                <div class="ins-barra-acciones">
                    <p class="text-sm text-gray-500">{{ __('Se descarga un .docx listo para revisar y firmar.') }}</p>
                    <button type="submit" class="ins-btn-primario">{{ __('Generar oficio') }}</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
