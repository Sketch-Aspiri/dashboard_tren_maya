@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\Proveedor> $proveedores */
    /** @var list<int> $tasas */
    /** @var int $sinDefinir */
    /** @var int $defecto */
    $campo = 'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-teal focus:ring-brand-teal text-sm';
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-heading text-xl font-semibold text-brand-green leading-tight">
            {{ __('Recursos materiales') }} — {{ __('Proveedores e IVA') }}
        </h2>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="rounded-xl border border-brand-green/10 bg-brand-mist p-4 text-sm text-brand-green">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                    <ul class="list-disc pl-5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-6">
                <p class="text-sm text-gray-600">
                    {{ __('Cada proveedor factura con su propio IVA (8 % o 16 %). En las solicitudes y en el ANEXO el IVA se calcula por separado para cada proveedor. Un proveedor sin IVA definido usa :defecto % y aparece como alerta en el control del filtro.', ['defecto' => $defecto]) }}
                </p>
                @if ($sinDefinir > 0)
                    <p class="mt-2 text-sm font-medium text-amber-700">
                        {{ trans_choice(':n proveedor sin IVA definido.|:n proveedores sin IVA definido.', $sinDefinir, ['n' => $sinDefinir]) }}
                    </p>
                @endif

                <form method="POST" action="{{ route('insumos.proveedores.store') }}" class="mt-4 flex flex-wrap items-end gap-3">
                    @csrf
                    <div class="grow max-w-md">
                        <x-input-label for="nuevo-proveedor" :value="__('Nuevo proveedor')" />
                        <input id="nuevo-proveedor" name="nombre" type="text" required maxlength="150" class="{{ $campo }}" value="{{ old('nombre') }}">
                    </div>
                    <div>
                        <x-input-label for="nuevo-iva" :value="__('IVA')" />
                        <select id="nuevo-iva" name="iva" class="{{ $campo }}">
                            <option value="">{{ __('Sin definir') }}</option>
                            @foreach ($tasas as $tasa)
                                <option value="{{ $tasa }}">{{ $tasa }} %</option>
                            @endforeach
                        </select>
                    </div>
                    <label class="flex min-h-[44px] items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" name="retiene_isr" value="1" class="rounded border-gray-300 text-brand-teal">
                        {{ __('Retiene ISR (persona física)') }}
                    </label>
                    <x-primary-button type="submit">{{ __('Agregar') }}</x-primary-button>
                </form>
            </div>

            <div class="overflow-x-auto rounded-xl border border-brand-green/10 bg-white shadow-sm shadow-brand-green/5">
                <table class="min-w-full text-sm">
                    <thead class="bg-brand-mist text-left text-xs font-semibold uppercase tracking-wide text-brand-green">
                        <tr>
                            <th class="px-4 py-3">{{ __('Proveedor') }}</th>
                            <th class="px-4 py-3">{{ __('IVA') }}</th>
                            <th class="px-4 py-3">{{ __('Retiene ISR') }}</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($proveedores as $proveedor)
                            @php $formId = 'proveedor-'.$proveedor->id; @endphp
                            <tr class="align-middle">
                                <td class="px-4 py-2 font-medium text-gray-900">{{ $proveedor->nombre }}</td>
                                <td class="px-4 py-2">
                                    <select form="{{ $formId }}" name="iva" class="{{ $campo }} w-36 {{ $proveedor->iva === null ? 'border-amber-400' : '' }}">
                                        <option value="">{{ __('Sin definir') }}</option>
                                        @foreach ($tasas as $tasa)
                                            <option value="{{ $tasa }}" @selected($proveedor->iva === $tasa)>{{ $tasa }} %</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="px-4 py-2">
                                    <input form="{{ $formId }}" type="checkbox" name="retiene_isr" value="1" @checked($proveedor->retiene_isr) class="rounded border-gray-300 text-brand-teal">
                                </td>
                                <td class="whitespace-nowrap px-4 py-2 text-right">
                                    <form id="{{ $formId }}" method="POST" action="{{ route('insumos.proveedores.update', $proveedor) }}" class="inline">
                                        @csrf
                                        @method('PUT')
                                        <button type="submit" class="min-h-[44px] px-2 text-brand-teal hover:underline">{{ __('Guardar') }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('insumos.proveedores.destroy', $proveedor) }}" class="inline"
                                          onsubmit="return confirm('{{ __('¿Eliminar este proveedor?') }}')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="min-h-[44px] px-2 text-red-600 hover:underline">{{ __('Eliminar') }}</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-8 text-center text-gray-500">{{ __('Aún no hay proveedores. Agrega uno o carga el catálogo.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div>
                <a href="{{ route('insumos.index') }}" class="text-sm text-gray-600 hover:text-brand-green">← {{ __('Volver') }}</a>
            </div>
        </div>
    </div>
</x-app-layout>
