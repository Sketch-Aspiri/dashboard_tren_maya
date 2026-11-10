@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\Proveedor> $proveedores */
    /** @var list<int> $tasas */
    /** @var int $sinDefinir */
    /** @var int $defecto */
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-insumos.titulo :titulo="__('Proveedores e IVA')"
                          :detalle="trans_choice(':n proveedor|:n proveedores', $proveedores->count(), ['n' => $proveedores->count()])" />
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <x-insumos.nav activo="proveedores" />

            <x-insumos.avisos />

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <section class="ins-card ins-card-pad lg:col-span-2">
                    <h3 class="ins-titulo">{{ __('Cómo se usa el IVA') }}</h3>
                    <p class="ins-ayuda mt-1">
                        {{ __('Cada proveedor factura con su propio IVA (8 % o 16 %). En las solicitudes y en el ANEXO el IVA se calcula por separado para cada proveedor. Un proveedor sin IVA definido usa :defecto % y aparece como alerta en el control del filtro.', ['defecto' => $defecto]) }}
                    </p>
                    @if ($sinDefinir > 0)
                        <p class="ins-aviso-alerta mt-4">
                            <span aria-hidden="true" class="font-bold">!</span>
                            <span>{{ trans_choice(':n proveedor sin IVA definido: asígnalo en la tabla (resaltado).|:n proveedores sin IVA definido: asígnalo en la tabla (resaltados).', $sinDefinir, ['n' => $sinDefinir]) }}</span>
                        </p>
                    @endif
                </section>

                <form method="POST" action="{{ route('insumos.proveedores.store') }}" class="ins-card ins-card-pad">
                    @csrf
                    <h3 class="ins-titulo">{{ __('Agregar proveedor') }}</h3>
                    <div class="mt-3 space-y-3">
                        <div>
                            <x-input-label for="nuevo-proveedor" :value="__('Nombre')" />
                            <input id="nuevo-proveedor" name="nombre" type="text" required maxlength="150" class="ins-campo mt-1" value="{{ old('nombre') }}">
                        </div>
                        <div>
                            <x-input-label for="nuevo-iva" :value="__('IVA')" />
                            <select id="nuevo-iva" name="iva" class="ins-campo mt-1">
                                <option value="">{{ __('Sin definir') }}</option>
                                @foreach ($tasas as $tasa)
                                    <option value="{{ $tasa }}">{{ $tasa }} %</option>
                                @endforeach
                            </select>
                        </div>
                        <label class="flex min-h-[44px] items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" name="retiene_isr" value="1" class="h-5 w-5 rounded border-gray-300 text-brand-teal focus:ring-brand-teal">
                            {{ __('Retiene ISR (persona física)') }}
                        </label>
                    </div>
                    <div class="mt-4 flex justify-end"><button type="submit" class="ins-btn-primario">{{ __('Agregar') }}</button></div>
                </form>
            </div>

            <div class="ins-tabla-marco">
                <table class="ins-tabla">
                    <caption class="sr-only">{{ __('Proveedores del fondo rotatorio') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Proveedor') }}</th>
                            <th scope="col">{{ __('RFC') }}</th>
                            <th scope="col">{{ __('Giro') }}</th>
                            <th scope="col">{{ __('Ubicación') }}</th>
                            <th scope="col">{{ __('IVA') }}</th>
                            <th scope="col">{{ __('Retiene ISR') }}</th>
                            <th scope="col"><span class="sr-only">{{ __('Acciones') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($proveedores as $proveedor)
                            @php $formId = 'proveedor-'.$proveedor->id; @endphp
                            <tr x-data="{ cambiado: false }" @change="cambiado = true">
                                <th scope="row" class="px-3 py-2.5 text-left font-medium text-gray-900 sm:px-4">{{ $proveedor->nombre }}</th>
                                <td class="whitespace-nowrap font-mono text-xs">{{ $proveedor->rfc ?: '—' }}</td>
                                <td>{{ $proveedor->giro ?: '—' }}</td>
                                <td>{{ $proveedor->ubicacion ?: '—' }}</td>
                                <td>
                                    <select form="{{ $formId }}" name="iva" aria-label="{{ __('IVA de :p', ['p' => $proveedor->nombre]) }}"
                                            class="ins-campo w-36 {{ $proveedor->iva === null ? '!border-amber-400 bg-amber-50' : '' }}">
                                        <option value="">{{ __('Sin definir') }}</option>
                                        @foreach ($tasas as $tasa)
                                            <option value="{{ $tasa }}" @selected($proveedor->iva === $tasa)>{{ $tasa }} %</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="text-center">
                                    <input form="{{ $formId }}" type="checkbox" name="retiene_isr" value="1" @checked($proveedor->retiene_isr) aria-label="{{ __('Retiene ISR :p', ['p' => $proveedor->nombre]) }}"
                                           class="mt-2.5 h-5 w-5 rounded border-gray-300 text-brand-teal focus:ring-brand-teal">
                                </td>
                                <td class="whitespace-nowrap">
                                    <div class="flex items-center justify-end gap-1">
                                        <form id="{{ $formId }}" method="POST" action="{{ route('insumos.proveedores.update', $proveedor) }}">
                                            @csrf
                                            @method('PUT')
                                            <button type="submit" class="ins-btn !min-h-[36px] !px-3" :class="cambiado ? 'bg-brand-green text-white hover:bg-brand-green-dark' : 'text-brand-teal hover:bg-brand-mist'">{{ __('Guardar') }}</button>
                                        </form>
                                        <x-confirmar-eliminar :action="route('insumos.proveedores.destroy', $proveedor)" :pregunta="__('¿Eliminar?')" :aria-label="__('Eliminar :p', ['p' => $proveedor->nombre])" />
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <x-insumos.vacio columnas="7" :titulo="__('Aún no hay proveedores.')">{{ __('Agrega uno o carga el catálogo.') }}</x-insumos.vacio>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
