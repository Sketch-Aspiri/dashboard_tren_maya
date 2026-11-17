@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\Estacion> $estaciones */
    /** @var \Illuminate\Support\Collection<int, \App\Models\HorarioPeriodo> $borradores */
    $meses = [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'];
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-horarios.titulo :titulo="__('Crear horario')" :detalle="__('Arma el rol de horarios del mes dentro del sistema, sin Excel')" />
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <x-horarios.nav activo="crear" />

            <x-insumos.avisos />

            @if ($borradores->isNotEmpty())
                <section class="ins-card ins-card-pad space-y-2" aria-labelledby="bor-titulo">
                    <h3 id="bor-titulo" class="ins-titulo">{{ __('Borradores en curso') }}</h3>
                    <ul class="space-y-1 text-sm">
                        @foreach ($borradores as $b)
                            <li><a href="{{ route('horarios.index', ['anio' => $b->anio, 'mes' => $b->mes, 'editar' => 1]) }}" class="font-semibold text-brand-teal underline">{{ $meses[$b->mes] }} {{ $b->anio }}</a>
                                <span class="text-gray-500">· {{ __('creado') }} {{ $b->created_at->setTimezone('America/Cancun')->format('d/m/Y H:i') }}</span></li>
                        @endforeach
                    </ul>
                </section>
            @endif

            <form method="POST" action="{{ route('horarios.crear.store') }}" class="ins-card ins-card-pad space-y-5">
                @csrf

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="mes" class="ins-etiqueta">{{ __('Mes') }}</label>
                        <select id="mes" name="mes" class="ins-campo mt-1" required>
                            @foreach ($meses as $n => $nombre)
                                <option value="{{ $n }}" @selected((int) old('mes', $mes) === $n)>{{ $nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="anio" class="ins-etiqueta">{{ __('Año') }}</label>
                        <input id="anio" name="anio" type="number" min="2020" max="2100" required value="{{ old('anio', $anio) }}" class="ins-campo mt-1">
                    </div>
                </div>

                <fieldset>
                    <legend class="ins-etiqueta">{{ __('Estaciones incluidas') }}</legend>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($estaciones as $e)
                            <label class="inline-flex items-center gap-2 text-sm">
                                <input type="checkbox" name="estaciones[]" value="{{ $e->id }}" @checked(old('estaciones') === null || in_array($e->id, (array) old('estaciones'))) class="rounded border-gray-300 text-brand-teal focus:ring-brand-teal">
                                {{ $e->nombre }}
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <fieldset>
                    <legend class="ins-etiqueta">{{ __('Punto de partida') }}</legend>
                    <div class="mt-2 space-y-2 text-sm">
                        <label class="flex items-start gap-2">
                            <input type="radio" name="modo" value="copiar" @checked(old('modo', 'copiar') === 'copiar') class="mt-1">
                            <span><span class="font-semibold text-gray-900">{{ __('Copiar el mes anterior') }}</span><br>
                                <span class="text-gray-600">{{ __('Cada persona conserva su patrón semanal del mes publicado anterior (cada fecha toma lo del mismo día de la semana, 4 a 6 semanas antes). Después lo ajustas.') }}</span></span>
                        </label>
                        <label class="flex items-start gap-2">
                            <input type="radio" name="modo" value="blanco" @checked(old('modo') === 'blanco') class="mt-1">
                            <span><span class="font-semibold text-gray-900">{{ __('Empezar en blanco') }}</span><br>
                                <span class="text-gray-600">{{ __('Entra el personal activo del directorio de cada estación, sin jornadas. Las llenas con «Rellenar por patrón» o celda por celda.') }}</span></span>
                        </label>
                    </div>
                </fieldset>

                <p class="text-xs text-gray-500">{{ __('Se crea un borrador que las estaciones no ven. Cuando esté listo, lo publicas y se les avisa.') }}</p>

                <button type="submit" class="ins-btn-primario">{{ __('Crear borrador') }}</button>
            </form>
        </div>
    </div>
</x-app-layout>
