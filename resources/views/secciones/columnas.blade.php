<x-app-layout>
    <x-slot name="header">
        <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $seccion->grupo->nombre }} · {{ $seccion->nombre }}</p>
        <h2 class="font-heading text-xl font-semibold leading-tight text-brand-green">{{ __('Columnas de la tabla') }}</h2>
    </x-slot>

    <div class="py-6 sm:py-10">
        <div class="mx-auto max-w-4xl space-y-5 px-4 sm:px-6 lg:px-8">
            <a href="{{ route('secciones.show', $seccion) }}" class="inline-flex min-h-[44px] items-center text-sm font-medium text-brand-teal hover:text-brand-green-dark">
                ← {{ __('Volver a la sección') }}
            </a>

            @include('secciones.partials.mensajes')

            <p class="text-sm text-gray-600">
                {{ __('Las columnas se crean solas al arrastrar un documento con tabla. Aquí puedes ajustarlas: cambiar el nombre, el tipo de dato, el orden, o agregar columnas nuevas. Cambiar el tipo convierte los valores ya capturados (lo que no se pueda convertir se conserva como texto).') }}
            </p>

            <div class="space-y-3">
                @forelse ($seccion->columnas as $columna)
                    <div x-data="{ editando: false, tipo: @js($columna->tipo->value) }"
                         class="rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between" x-show="! editando">
                            <div>
                                <p class="font-medium text-gray-900">{{ $columna->nombre }}</p>
                                <p class="text-xs text-gray-500">
                                    {{ $columna->tipo->label() }}
                                    @if ($columna->listaOpciones() !== []) · {{ implode(', ', $columna->listaOpciones()) }} @endif
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center gap-1">
                                @foreach (['arriba' => '↑', 'abajo' => '↓'] as $direccion => $flecha)
                                    <form method="POST" action="{{ route('secciones.columnas.mover', [$seccion, $columna]) }}">
                                        @csrf
                                        <input type="hidden" name="direccion" value="{{ $direccion }}">
                                        <button type="submit" class="min-h-[36px] min-w-[36px] rounded-md text-gray-600 hover:bg-gray-100" aria-label="{{ __('Mover :dir', ['dir' => $direccion]) }}">{{ $flecha }}</button>
                                    </form>
                                @endforeach
                                <button type="button" @click="editando = true" class="min-h-[36px] rounded-md px-2 text-sm font-medium text-brand-teal hover:bg-brand-mist">{{ __('Editar') }}</button>
                                <x-confirmar-eliminar :action="route('secciones.columnas.destroy', [$seccion, $columna])"
                                                      :pregunta="__('¿Eliminar la columna y sus valores?')" />
                            </div>
                        </div>

                        <form method="POST" action="{{ route('secciones.columnas.update', [$seccion, $columna]) }}" x-show="editando" x-cloak class="space-y-3">
                            @csrf
                            @method('PUT')
                            @include('secciones.partials.campos-columna', ['columna' => $columna, 'prefijo' => 'col-'.$columna->id])
                            <div class="flex justify-end gap-2">
                                <button type="button" @click="editando = false" class="min-h-[44px] rounded-md px-4 text-sm font-medium text-gray-700 hover:bg-gray-100">{{ __('Cancelar') }}</button>
                                <x-primary-button>{{ __('Guardar') }}</x-primary-button>
                            </div>
                        </form>
                    </div>
                @empty
                    <div class="rounded-xl border border-dashed border-brand-green/25 bg-white p-6 text-center text-sm text-gray-600">
                        {{ __('Todavía no hay columnas.') }}
                    </div>
                @endforelse
            </div>

            <div class="rounded-xl border border-brand-green/10 bg-white p-5 shadow-sm shadow-brand-green/5" x-data="{ tipo: @js(old('tipo', 'texto')) }">
                <h3 class="font-heading text-base font-semibold text-brand-green">{{ __('Agregar columna') }}</h3>
                <form method="POST" action="{{ route('secciones.columnas.store', $seccion) }}" class="mt-3 space-y-3">
                    @csrf
                    @include('secciones.partials.campos-columna', ['columna' => null, 'prefijo' => 'nueva'])
                    <div class="flex justify-end">
                        <x-primary-button>{{ __('Agregar') }}</x-primary-button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
