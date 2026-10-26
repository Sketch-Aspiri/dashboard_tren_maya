{{-- Una sección (y, recursivamente, sus subsecciones) en "Administrar menú".
     Variables: $seccion (con 'hijas' ya asignada), $nivel (0 = raíz),
     $primera/$ultima (entre sus hermanas), $grupos y $ubicaciones. Las
     subsecciones se pintan como <li> hermanos con sangría, no anidados. --}}
@php
    $puedeAnidar = $nivel + 1 < \App\Models\Seccion::PROFUNDIDAD_MAXIMA;
    $hijas = $seccion->getRelation('hijas');
    $ubicacionActual = $seccion->parent_id ? 's-'.$seccion->parent_id : 'g-'.$seccion->menu_grupo_id;
    $opcionesUbicacion = collect($ubicaciones)->reject(fn (array $opcion) => in_array($seccion->id, $opcion['ruta'], true));
@endphp
<li id="seccion-{{ $seccion->id }}" class="scroll-mt-24 py-3 pe-5" style="padding-inline-start: {{ 1.25 + $nivel * 1.5 }}rem" x-data="{ editando: false, agregando: false }">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between" x-show="! editando && ! agregando">
        <div class="min-w-0">
            @if ($nivel > 0)<span class="me-1 text-gray-400" aria-hidden="true">↳</span>@endif
            <a href="{{ route('secciones.show', $seccion) }}" class="font-medium text-gray-900 hover:text-brand-teal">{{ $seccion->nombre }}</a>
            @unless ($seccion->is_visible)
                <span class="ms-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">{{ __('Oculta') }}</span>
            @endunless
            @if ($seccion->solo_documentos)
                <span class="ms-1 rounded-full bg-brand-mist px-2 py-0.5 text-xs text-brand-green">{{ __('Solo documentos') }}</span>
            @endif
            <p class="text-xs text-gray-500">
                {{ __(':f registros · :d documentos', ['f' => $seccion->filas_count, 'd' => $seccion->archivos_count]) }}
                @if ($hijas->isNotEmpty())
                    · {{ trans_choice(':count subsección|:count subsecciones', $hijas->count()) }}
                @endif
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-1">
            @foreach (['arriba' => '↑', 'abajo' => '↓'] as $direccion => $flecha)
                <form method="POST" action="{{ route('secciones.mover', $seccion) }}">
                    @csrf
                    <input type="hidden" name="direccion" value="{{ $direccion }}">
                    <button type="submit" @disabled(($direccion === 'arriba' && $primera) || ($direccion === 'abajo' && $ultima)) class="min-h-[44px] min-w-[44px] rounded-md text-gray-600 hover:bg-gray-100 disabled:opacity-30" aria-label="{{ __('Mover :nombre hacia :dir', ['nombre' => $seccion->nombre, 'dir' => $direccion]) }}"><span aria-hidden="true">{{ $flecha }}</span></button>
                </form>
            @endforeach
            @if ($puedeAnidar)
                <button type="button" @click="agregando = true" class="min-h-[36px] rounded-md px-2 text-sm font-medium text-brand-teal hover:bg-brand-mist">{{ __('+ Subsección') }}</button>
            @endif
            <form method="POST" action="{{ route('secciones.update', $seccion) }}">
                @csrf
                @method('PUT')
                <input type="hidden" name="is_visible" value="{{ $seccion->is_visible ? 0 : 1 }}">
                <button type="submit" class="min-h-[36px] rounded-md px-2 text-sm font-medium text-gray-700 hover:bg-gray-100">
                    {{ $seccion->is_visible ? __('Ocultar') : __('Mostrar') }}
                </button>
            </form>
            <button type="button" @click="editando = true" class="min-h-[36px] rounded-md px-2 text-sm font-medium text-brand-teal hover:bg-brand-mist">{{ __('Editar') }}</button>
            <x-confirmar-eliminar :action="route('secciones.destroy', $seccion)" :etiqueta="__('Quitar del menú')" :confirmar="__('Sí, quitar')"
                                  :pregunta="$hijas->isEmpty() ? __('¿Quitar «:nombre» del menú?', ['nombre' => $seccion->nombre]) : __('¿Quitar «:nombre» y sus :n subsecciones del menú?', ['nombre' => $seccion->nombre, 'n' => count(\App\Support\ArbolSecciones::aplanar($hijas))])" />
        </div>
    </div>

    {{-- Nueva subsección --}}
    @if ($puedeAnidar)
        <form method="POST" action="{{ route('secciones.store') }}" x-show="agregando" x-cloak class="grid grid-cols-1 gap-3 sm:grid-cols-3">
            @csrf
            <input type="hidden" name="parent_id" value="{{ $seccion->id }}">
            <div class="sm:col-span-2">
                <label for="sub-nombre-{{ $seccion->id }}" class="block text-xs font-medium text-gray-700">{{ __('Nueva subsección dentro de «:nombre»', ['nombre' => $seccion->nombre]) }}</label>
                <input id="sub-nombre-{{ $seccion->id }}" name="nombre" required maxlength="160"
                       class="mt-1 block min-h-[40px] w-full rounded-md border-gray-300 text-sm shadow-sm">
            </div>
            <div class="flex items-end gap-2">
                <button type="button" @click="agregando = false" class="min-h-[40px] rounded-md px-3 text-sm text-gray-700 hover:bg-gray-100">{{ __('Cancelar') }}</button>
                <x-primary-button>{{ __('Crear') }}</x-primary-button>
            </div>
        </form>
    @endif

    <form method="POST" action="{{ route('secciones.update', $seccion) }}" x-show="editando" x-cloak class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        @csrf
        @method('PUT')
        <div>
            <label for="sec-nombre-{{ $seccion->id }}" class="block text-xs font-medium text-gray-700">{{ __('Nombre') }}</label>
            <input id="sec-nombre-{{ $seccion->id }}" name="nombre" value="{{ $seccion->nombre }}" required maxlength="160"
                   class="mt-1 block min-h-[40px] w-full rounded-md border-gray-300 text-sm shadow-sm">
        </div>
        <div class="sm:col-span-2">
            <label for="sec-ubicacion-{{ $seccion->id }}" class="block text-xs font-medium text-gray-700">{{ __('Ubicación (grupo o sección donde vive)') }}</label>
            <select id="sec-ubicacion-{{ $seccion->id }}" name="ubicacion" class="mt-1 block min-h-[40px] w-full rounded-md border-gray-300 text-sm shadow-sm">
                @foreach ($opcionesUbicacion as $opcion)
                    <option value="{{ $opcion['valor'] }}" @selected($opcion['valor'] === $ubicacionActual)>{{ $opcion['etiqueta'] }}</option>
                @endforeach
            </select>
        </div>
        <div class="sm:col-span-3">
            <label for="sec-desc-{{ $seccion->id }}" class="block text-xs font-medium text-gray-700">{{ __('Descripción') }}</label>
            <input id="sec-desc-{{ $seccion->id }}" name="descripcion" value="{{ $seccion->descripcion }}" maxlength="2000"
                   class="mt-1 block min-h-[40px] w-full rounded-md border-gray-300 text-sm shadow-sm">
        </div>
        <div class="sm:col-span-3">
            <input type="hidden" name="solo_documentos" value="0">
            <label for="sec-solo-docs-{{ $seccion->id }}" class="inline-flex min-h-[44px] items-center gap-2 text-sm text-gray-700">
                <input id="sec-solo-docs-{{ $seccion->id }}" type="checkbox" name="solo_documentos" value="1" @checked($seccion->solo_documentos)
                       class="rounded border-gray-300 text-brand-teal shadow-sm focus:ring-brand-teal">
                <span>{{ __('Solo documentos (quitar la parte de tabla)') }}</span>
            </label>
            <p class="ms-6 text-xs text-gray-500">{{ __('Oculta la tabla, las columnas, la exportación y las cargas. Los datos ya capturados se conservan si lo desactivas.') }}</p>
        </div>
        <div class="flex gap-2 sm:col-span-3 sm:justify-end">
            <button type="button" @click="editando = false" class="min-h-[40px] rounded-md px-3 text-sm text-gray-700 hover:bg-gray-100">{{ __('Cancelar') }}</button>
            <x-primary-button>{{ __('Guardar') }}</x-primary-button>
        </div>
    </form>
</li>
@foreach ($hijas as $hija)
    @include('secciones.partials.nodo-administrar', ['seccion' => $hija, 'nivel' => $nivel + 1, 'primera' => $loop->first, 'ultima' => $loop->last])
@endforeach
