<x-app-layout>
    <x-slot name="header">
        <x-vacaciones.titulo :titulo="__('Catálogo :anio', ['anio' => $anio])" :detalle="__('Periodos, días inhábiles y topes por estación')" />
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            <x-vacaciones.nav activo="catalogo" />
            <x-insumos.avisos />
            @error('plan') <p class="ins-aviso-error">{{ $message }}</p> @enderror
            @if ($errors->any() && ! $errors->has('plan'))
                <p class="ins-aviso-error">{{ $errors->first() }}</p>
            @endif

            <section class="ins-card ins-card-pad space-y-3">
                <h3 class="font-heading text-base font-semibold text-gray-900">{{ __('Periodos de :anio', ['anio' => $anio]) }}</h3>
                <p class="text-sm text-gray-600">{{ __('Se generan con la regla del año anterior: cada lunes inicia un periodo de 5 y otro de 10 días hábiles sin contar inhábiles; Semana Santa, Pascua y el cierre de año quedan sin autorizar. Revisa las fechas y ajusta lo que haga falta.') }}</p>
                <form method="POST" action="{{ route('vacaciones.seleccion.catalogo.generar') }}">
                    @csrf
                    <button type="submit" class="ins-btn-primario">{{ $periodos->isEmpty() ? __('Generar catálogo') : __('Completar catálogo') }}</button>
                </form>
            </section>

            <section class="ins-card ins-card-pad space-y-3">
                <h3 class="font-heading text-base font-semibold text-gray-900">{{ __('Días inhábiles') }}</h3>
                <ul class="divide-y divide-gray-100 text-sm">
                    @forelse ($inhabiles as $i)
                        <li class="flex items-center justify-between gap-2 py-2">
                            <span>{{ $i->fecha->format('d/m/Y') }} · {{ $i->nombre }}</span>
                            <form method="POST" action="{{ route('vacaciones.seleccion.catalogo.inhabil.destroy', $i) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="ins-btn-neutro">{{ __('Quitar') }}</button>
                            </form>
                        </li>
                    @empty
                        <li class="py-2 text-gray-500">{{ __('Aún no hay días inhábiles.') }}</li>
                    @endforelse
                </ul>
                <form method="POST" action="{{ route('vacaciones.seleccion.catalogo.inhabil.store') }}" class="flex flex-wrap items-center gap-2">
                    @csrf
                    <input type="date" name="fecha" required class="ins-campo" aria-label="{{ __('Fecha inhábil') }}">
                    <input type="text" name="nombre" required maxlength="80" class="ins-campo w-56" placeholder="{{ __('Nombre') }}" aria-label="{{ __('Nombre del día inhábil') }}">
                    <button type="submit" class="ins-btn-secundario">{{ __('Agregar') }}</button>
                </form>
                <p class="text-xs text-gray-500">{{ __('Los cambios en inhábiles no mueven periodos ya generados; sirven para el siguiente "Completar catálogo".') }}</p>
            </section>

            <section class="ins-card ins-card-pad space-y-3">
                <h3 class="font-heading text-base font-semibold text-gray-900">{{ __('Tope de personas de vacaciones a la vez, por estación') }}</h3>
                <form method="POST" action="{{ route('vacaciones.seleccion.catalogo.topes') }}" class="space-y-3">
                    @csrf
                    @method('PUT')
                    <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($estaciones as $e)
                            <label class="flex items-center justify-between gap-2 rounded border border-gray-200 px-3 py-2 text-sm">
                                <span>{{ $e->nombre }}</span>
                                <input type="number" min="1" max="20" name="topes[{{ $e->id }}]" value="{{ $topes[$e->id] ?? $predeterminado }}" class="ins-campo w-20">
                            </label>
                        @endforeach
                    </div>
                    <button type="submit" class="ins-btn-primario">{{ __('Guardar topes') }}</button>
                </form>
            </section>

            @foreach ($periodos as $trimestre => $items)
                <section class="ins-card ins-card-pad space-y-2">
                    <h3 class="font-heading text-base font-semibold text-gray-900">{{ $trimestre }}º {{ __('trimestre') }}</h3>
                    <div class="ins-tabla-marco">
                        <table class="ins-tabla">
                            <thead><tr><th scope="col">{{ __('Periodo') }}</th><th scope="col">{{ __('Días') }}</th><th scope="col">{{ __('Estado') }}</th><th scope="col"><span class="sr-only">{{ __('Acción') }}</span></th></tr></thead>
                            <tbody>
                                @foreach ($items as $p)
                                    <tr>
                                        <td class="fuerte">{{ $p->etiqueta() }}</td>
                                        <td>{{ $p->dias }}</td>
                                        <td><span class="{{ $p->autorizado ? 'ins-chip-ok' : 'ins-chip-neutro' }}">{{ $p->autorizado ? __('Autorizado') : ($p->nota ?? __('No autorizado')) }}</span></td>
                                        <td>
                                            <form method="POST" action="{{ route('vacaciones.seleccion.catalogo.periodo', $p) }}">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="ins-btn-neutro">{{ $p->autorizado ? __('Desautorizar') : __('Autorizar') }}</button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endforeach
        </div>
    </div>
</x-app-layout>
