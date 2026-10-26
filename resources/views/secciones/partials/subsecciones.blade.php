{{-- Carpetas de la sección: sus subsecciones como tarjetas y alta rápida.
     Variables: $seccion, $subsecciones (con conteos), $puedeAnidar. --}}
@if ($subsecciones->isNotEmpty() || ($puedeAnidar && auth()->user()->can('create', \App\Models\Seccion::class)))
    <section aria-labelledby="subsecciones-titulo" class="rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5" x-data="{ agregando: @js($errors->has('nombre') && old('parent_id') == $seccion->id) }">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 id="subsecciones-titulo" class="font-heading text-sm font-semibold text-brand-green">{{ __('Subsecciones') }}</h3>
            @if ($puedeAnidar)
                @can('create', \App\Models\Seccion::class)
                    <button type="button" @click="agregando = ! agregando" :aria-expanded="agregando.toString()"
                            class="min-h-[44px] rounded-md px-3 text-sm font-medium text-brand-teal hover:bg-brand-mist">{{ __('+ Nueva subsección') }}</button>
                @endcan
            @endif
        </div>

        @if ($puedeAnidar)
            @can('create', \App\Models\Seccion::class)
                <form method="POST" action="{{ route('secciones.store') }}" x-show="agregando" x-cloak class="mt-3 flex flex-col gap-2 sm:flex-row">
                    @csrf
                    <input type="hidden" name="parent_id" value="{{ $seccion->id }}">
                    <label for="subseccion-nombre" class="sr-only">{{ __('Nombre de la subsección') }}</label>
                    <input id="subseccion-nombre" name="nombre" required maxlength="160" placeholder="{{ __('Nombre (p. ej. una estación o una persona)') }}"
                           class="min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-teal focus:ring-brand-teal sm:max-w-sm">
                    <x-primary-button>{{ __('Crear subsección') }}</x-primary-button>
                </form>
            @endcan
        @endif

        @if ($subsecciones->isNotEmpty())
            <ul class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($subsecciones as $subseccion)
                    <li>
                        <a href="{{ route('secciones.show', $subseccion) }}"
                           class="flex min-h-[56px] flex-col justify-center rounded-lg border border-gray-200 px-3 py-2 hover:border-brand-teal hover:bg-brand-mist focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">
                            <span class="font-medium text-gray-900">
                                <span aria-hidden="true">📁</span> {{ $subseccion->nombre }}
                                @unless ($subseccion->is_visible)
                                    <span class="ms-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-normal text-gray-600">{{ __('Oculta') }}</span>
                                @endunless
                            </span>
                            <span class="text-xs text-gray-500">
                                {{ __(':f registros · :d documentos', ['f' => $subseccion->filas_count, 'd' => $subseccion->archivos_count]) }}
                                @if ($subseccion->hijas_count > 0)
                                    · {{ trans_choice(':count subsección|:count subsecciones', $subseccion->hijas_count) }}
                                @endif
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
@endif
