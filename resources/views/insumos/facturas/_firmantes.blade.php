{{-- Cargo, nombre y firma digital (opcional) de quien recibe y de quien autoriza. Espera en Alpine `f` ({recibio, autorizo}) y `elegirFirma(clave, evento)`. --}}
@php
    $etiquetas = ['recibio' => __('Recibió'), 'autorizo' => __('Autorizó')];
@endphp
<div class="grid gap-4 md:grid-cols-2">
    @foreach ($etiquetas as $clave => $etiqueta)
        <fieldset class="space-y-3 rounded-lg border border-gray-200 bg-white p-4">
            <legend class="px-1 text-sm font-semibold text-brand-green">{{ $etiqueta }}</legend>
            <div>
                <label for="{{ $prefijo }}-{{ $clave }}-cargo" class="ins-etiqueta">{{ __('Cargo') }}</label>
                <textarea id="{{ $prefijo }}-{{ $clave }}-cargo" name="firmantes[{{ $clave }}][cargo]" rows="2" maxlength="200" x-model="f.{{ $clave }}.cargo"
                          :placeholder="cargoBase.{{ $clave }}" class="ins-campo mt-1"></textarea>
                <x-input-error :messages="$errors->get('firmantes.'.$clave.'.cargo')" class="mt-1" />
            </div>
            <div>
                <label for="{{ $prefijo }}-{{ $clave }}-nombre" class="ins-etiqueta">{{ __('Nombre') }}</label>
                <input id="{{ $prefijo }}-{{ $clave }}-nombre" type="text" name="firmantes[{{ $clave }}][nombre]" maxlength="150" x-model="f.{{ $clave }}.nombre"
                       :placeholder="nombreBase.{{ $clave }}" class="ins-campo mt-1">
                <x-input-error :messages="$errors->get('firmantes.'.$clave.'.nombre')" class="mt-1" />
            </div>
            <div>
                <label for="{{ $prefijo }}-{{ $clave }}-firma" class="ins-etiqueta">{{ __('Firma digital (PNG o JPG, opcional)') }}</label>
                <input id="{{ $prefijo }}-{{ $clave }}-firma" type="file" name="firmantes[{{ $clave }}][firma]" accept="image/png,image/jpeg" @change="elegirFirma('{{ $clave }}', $event)" class="ins-archivo mt-1">
                <x-input-error :messages="$errors->get('firmantes.'.$clave.'.firma')" class="mt-1" />
            </div>
        </fieldset>
    @endforeach
</div>
<p class="text-xs text-gray-500">{{ __('Si dejas cargo o nombre vacío se usa el de siempre (el texto gris). La firma se inserta en el espacio de la firma del PDF; no se guarda, hay que subirla de nuevo al regenerar.') }}</p>
