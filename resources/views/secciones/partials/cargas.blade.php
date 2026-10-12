@if ($cargas->isEmpty())
    <div class="rounded-xl border border-dashed border-brand-green/25 bg-white p-8 text-center text-sm text-gray-600 shadow-sm shadow-brand-green/5">
        {{ __('Aquí aparece el historial de documentos con tabla que se han cargado a esta sección, y desde aquí se puede deshacer la más reciente.') }}
    </div>
@else
    <div class="overflow-x-auto rounded-xl border border-brand-green/10 bg-white shadow-sm shadow-brand-green/5">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-brand-mist">
                <tr>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase text-brand-green">{{ __('Fecha') }}</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase text-brand-green">{{ __('Documento') }}</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase text-brand-green">{{ __('Estado') }}</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase text-brand-green">{{ __('Resultado') }}</th>
                    <th scope="col" class="px-4 py-3"><span class="sr-only">{{ __('Acciones') }}</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach ($cargas as $carga)
                    <tr>
                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">
                            {{ $carga->created_at->format('d/m/Y H:i') }}
                            <span class="block text-xs text-gray-500">{{ $carga->usuario?->name }}</span>
                        </td>
                        <td class="px-4 py-3 text-gray-700">
                            {{ $carga->archivo?->nombre_original ?? '—' }}
                            @if ($carga->origen === \App\Models\SeccionImportacion::ORIGEN_IA)
                                <span class="ms-1 rounded-full bg-brand-mist px-2 py-0.5 text-xs text-brand-green">IA</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-4 py-3">
                            <span @class([
                                'rounded-full px-2 py-1 text-xs font-semibold',
                                'bg-amber-100 text-amber-800' => $carga->estado === \App\Enums\EstadoImportacion::VistaPrevia,
                                'bg-brand-mist text-brand-green' => $carga->estado === \App\Enums\EstadoImportacion::Aplicada,
                                'bg-gray-100 text-gray-700' => $carga->estado === \App\Enums\EstadoImportacion::Deshecha,
                            ])>{{ $carga->estado->label() }}</span>
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-600">
                            @if ($carga->resumen)
                                {{ $carga->modo?->label() }}:
                                {{ __(':n nuevas, :a actualizadas, :e eliminadas', ['n' => $carga->resumen['nuevas'], 'a' => $carga->resumen['actualizadas'], 'e' => $carga->resumen['eliminadas']]) }}
                            @else
                                —
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-4 py-2 text-right">
                            @if ($puedeGestionar && $carga->isVistaPrevia())
                                <a href="{{ route('secciones.importaciones.show', [$seccion, $carga]) }}" class="min-h-[36px] rounded-md px-2 py-1.5 text-sm font-medium text-brand-teal hover:bg-brand-mist">{{ __('Revisar') }}</a>
                            @endif
                            @if ($puedeGestionar && $cargaDeshacible && $cargaDeshacible['carga']->is($carga))
                                @if ($cargaDeshacible['motivo'] === null)
                                    <x-confirmar-eliminar :action="route('secciones.importaciones.deshacer', [$seccion, $carga])" metodo="POST" tono="neutro"
                                                          :etiqueta="__('Deshacer')" :confirmar="__('Sí, deshacer')"
                                                          :pregunta="__('¿Deshacer? La tabla vuelve a como estaba antes de esta carga.')" />
                                @else
                                    <span class="text-xs text-gray-600">{{ $cargaDeshacible['motivo'] }}</span>
                                @endif
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <p class="mt-2 text-xs text-gray-600">{{ __('Solo se puede deshacer la carga aplicada más reciente, y solo si sus registros no se han modificado después.') }}</p>
@endif
