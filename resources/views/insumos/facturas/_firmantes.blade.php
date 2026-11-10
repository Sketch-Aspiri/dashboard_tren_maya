{{-- Cargo, nombre y firma digital (opcional) de quien recibe y de quien autoriza. Espera en Alpine `f` ({recibio, autorizo}) y `elegirFirma(clave, evento)`. --}}
@php
    $etiquetas = ['recibio' => __('Recibió'), 'autorizo' => __('Autorizó')];
    $archivo = 'mt-1 block w-full text-sm text-gray-700 file:mr-3 file:rounded-md file:border-0 file:bg-brand-mist file:px-3 file:py-2 file:text-xs file:font-semibold file:uppercase file:text-brand-green hover:file:bg-brand-green/10';
@endphp
<div class="grid gap-4 md:grid-cols-2">
    @foreach ($etiquetas as $clave => $etiqueta)
        <fieldset class="space-y-3 rounded-lg border border-gray-200 bg-white p-3">
            <legend class="px-1 text-sm font-semibold text-brand-green">{{ $etiqueta }}</legend>
            <div>
                <x-input-label :for="$prefijo.'-'.$clave.'-cargo'" :value="__('Cargo')" />
                <textarea id="{{ $prefijo }}-{{ $clave }}-cargo" name="firmantes[{{ $clave }}][cargo]" rows="2" maxlength="200" x-model="f.{{ $clave }}.cargo"
                          class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-teal focus:ring-brand-teal"></textarea>
                <x-input-error :messages="$errors->get('firmantes.'.$clave.'.cargo')" class="mt-1" />
            </div>
            <div>
                <x-input-label :for="$prefijo.'-'.$clave.'-nombre'" :value="__('Nombre')" />
                <x-text-input :id="$prefijo.'-'.$clave.'-nombre'" name="firmantes[{{ $clave }}][nombre]" class="mt-1 block w-full" maxlength="150" x-model="f.{{ $clave }}.nombre" />
                <x-input-error :messages="$errors->get('firmantes.'.$clave.'.nombre')" class="mt-1" />
            </div>
            <div>
                <x-input-label :for="$prefijo.'-'.$clave.'-firma'" :value="__('Firma digital (PNG o JPG, opcional)')" />
                <input id="{{ $prefijo }}-{{ $clave }}-firma" type="file" name="firmantes[{{ $clave }}][firma]" accept="image/png,image/jpeg" @change="elegirFirma('{{ $clave }}', $event)" class="{{ $archivo }}">
                <x-input-error :messages="$errors->get('firmantes.'.$clave.'.firma')" class="mt-1" />
            </div>
        </fieldset>
    @endforeach
</div>
<p class="mt-2 text-xs text-gray-500">{{ __('Si dejas cargo o nombre vacío se usa el de siempre. La firma se inserta en el espacio de la firma del PDF; no se guarda, hay que subirla de nuevo al regenerar.') }}</p>
