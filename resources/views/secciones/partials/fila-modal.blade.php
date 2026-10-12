{{-- Formulario de alta/edición de un registro, en ventana modal. El mismo
     formulario sirve para ambos: Alpine.data('filaFormulario') cambia la URL
     y el método y precarga los valores. Si la validación falla, el servidor
     regresa con old() y la ventana se vuelve a abrir con los errores. --}}
<div x-data="filaFormulario({
        urlNueva: @js(route('secciones.filas.store', $seccion)),
        urlEditar: @js(route('secciones.filas.update', [$seccion, '__ID__'])),
        reabrir: @js($errors->any() && old('_modal_fila') !== null),
        idAnterior: @js(ctype_digit((string) old('_modal_fila')) ? (int) old('_modal_fila') : null),
        valoresAnteriores: @js(old('datos', [])),
     })"
     @fila-nueva.window="nueva()"
     @fila-editar.window="editar($event.detail)"
     @keydown.escape.window="cerrar()">
    <div x-show="abierta" x-cloak class="fixed inset-0 z-50 flex items-end justify-center bg-gray-900/50 p-0 sm:items-center sm:p-4"
         role="dialog" aria-modal="true" aria-labelledby="titulo-fila-modal">
        <div class="flex max-h-[92dvh] w-full max-w-2xl flex-col rounded-t-2xl bg-white shadow-xl sm:rounded-2xl">
            <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4">
                <h3 id="titulo-fila-modal" class="font-heading text-lg font-semibold text-brand-green"
                    x-text="id ? @js(__('Editar registro')) : @js(__('Nuevo registro'))"></h3>
                <button type="button" @click="cerrar()" class="min-h-[44px] min-w-[44px] rounded-md text-gray-500 hover:bg-gray-100" aria-label="{{ __('Cerrar') }}">✕</button>
            </div>

            <form method="POST" :action="url" class="flex min-h-0 flex-1 flex-col">
                @csrf
                <template x-if="id"><input type="hidden" name="_method" value="PUT"></template>
                <input type="hidden" name="_modal_fila" :value="id ?? 'nueva'">

                <div class="grid flex-1 grid-cols-1 gap-4 overflow-y-auto px-5 py-4 sm:grid-cols-2">
                    @foreach ($columnas as $columna)
                        @php $campo = 'campo-'.$columna->clave; @endphp
                        <div class="{{ $columna->tipo === \App\Enums\TipoColumna::Texto ? 'sm:col-span-2' : '' }}">
                            <label for="{{ $campo }}" class="block text-sm font-medium text-gray-700">{{ $columna->nombre }}</label>
                            @switch($columna->tipo)
                                @case(\App\Enums\TipoColumna::Numero)
                                    <input id="{{ $campo }}" type="number" step="any" name="datos[{{ $columna->clave }}]" x-model="valores[@js($columna->clave)]"
                                           class="mt-1 block min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-teal focus:ring-brand-teal">
                                    @break
                                @case(\App\Enums\TipoColumna::Fecha)
                                    <input id="{{ $campo }}" type="date" name="datos[{{ $columna->clave }}]" x-model="valores[@js($columna->clave)]"
                                           class="mt-1 block min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-teal focus:ring-brand-teal">
                                    @break
                                @case(\App\Enums\TipoColumna::Booleano)
                                    <select id="{{ $campo }}" name="datos[{{ $columna->clave }}]" x-model="valores[@js($columna->clave)]"
                                            class="mt-1 block min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-teal focus:ring-brand-teal">
                                        <option value="">—</option>
                                        <option value="1">{{ __('Sí') }}</option>
                                        <option value="0">{{ __('No') }}</option>
                                    </select>
                                    @break
                                @case(\App\Enums\TipoColumna::Lista)
                                    <select id="{{ $campo }}" name="datos[{{ $columna->clave }}]" x-model="valores[@js($columna->clave)]"
                                            class="mt-1 block min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-teal focus:ring-brand-teal">
                                        <option value="">—</option>
                                        @foreach ($columna->listaOpciones() as $opcion)
                                            <option value="{{ $opcion }}">{{ $opcion }}</option>
                                        @endforeach
                                    </select>
                                    @break
                                @default
                                    <textarea id="{{ $campo }}" name="datos[{{ $columna->clave }}]" rows="2" maxlength="5000" x-model="valores[@js($columna->clave)]"
                                              class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-teal focus:ring-brand-teal"></textarea>
                            @endswitch
                            <x-input-error :messages="$errors->get('datos.'.$columna->clave)" class="mt-1" />
                        </div>
                    @endforeach
                </div>

                <div class="flex justify-end gap-2 border-t border-gray-100 px-5 py-4">
                    <button type="button" @click="cerrar()" class="min-h-[44px] rounded-md px-4 text-sm font-medium text-gray-700 hover:bg-gray-100">{{ __('Cancelar') }}</button>
                    <x-primary-button>{{ __('Guardar') }}</x-primary-button>
                </div>
            </form>
        </div>
    </div>
</div>
