{{-- Campos compartidos por "Agregar columna" y "Editar columna". El padre
     define x-data con "tipo" para mostrar las opciones solo en listas. --}}
<div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
    <div>
        <label for="{{ $prefijo }}-nombre" class="block text-sm font-medium text-gray-700">{{ __('Nombre') }}</label>
        <input id="{{ $prefijo }}-nombre" type="text" name="nombre" required maxlength="120"
               value="{{ $columna?->nombre ?? old('nombre') }}"
               class="mt-1 block min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-teal focus:ring-brand-teal">
    </div>
    <div>
        <label for="{{ $prefijo }}-tipo" class="block text-sm font-medium text-gray-700">{{ __('Tipo de dato') }}</label>
        <select id="{{ $prefijo }}-tipo" name="tipo" x-model="tipo"
                class="mt-1 block min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-teal focus:ring-brand-teal">
            @foreach ($tiposColumna as $tipo)
                <option value="{{ $tipo->value }}">{{ $tipo->label() }}</option>
            @endforeach
        </select>
    </div>
    <div class="sm:col-span-2" x-show="tipo === 'lista'" x-cloak>
        <label for="{{ $prefijo }}-opciones" class="block text-sm font-medium text-gray-700">{{ __('Opciones (una por renglón)') }}</label>
        <textarea id="{{ $prefijo }}-opciones" name="opciones_texto" rows="4"
                  class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-teal focus:ring-brand-teal">{{ $columna ? implode("\n", $columna->listaOpciones()) : old('opciones_texto') }}</textarea>
    </div>
</div>
