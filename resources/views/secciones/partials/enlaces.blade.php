{{-- Pestaña Enlaces. Variables: $seccion, $enlaces, $puedeGestionar. Solo se
     pintan URL http(s) (validadas al guardar) y siempre con rel="noopener noreferrer". --}}
@if ($puedeGestionar)
    <form method="POST" action="{{ route('secciones.enlaces.store', $seccion) }}"
          class="mb-4 grid grid-cols-1 gap-3 rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:grid-cols-6">
        @csrf
        <div class="sm:col-span-2">
            <label for="enlace-titulo" class="block text-xs font-medium text-gray-700">{{ __('Título') }}</label>
            <input id="enlace-titulo" name="titulo" required maxlength="160" value="{{ old('titulo') }}"
                   class="mt-1 block min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-teal focus:ring-brand-teal">
            @error('titulo')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>
        <div class="sm:col-span-3">
            <label for="enlace-url" class="block text-xs font-medium text-gray-700">{{ __('Dirección (https://…)') }}</label>
            <input id="enlace-url" name="url" type="url" required maxlength="2000" placeholder="https://" value="{{ old('url') }}"
                   class="mt-1 block min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-teal focus:ring-brand-teal">
            @error('url')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>
        <div class="flex items-end">
            <x-primary-button class="w-full">{{ __('Agregar enlace') }}</x-primary-button>
        </div>
        <div class="sm:col-span-6">
            <label for="enlace-nota" class="block text-xs font-medium text-gray-700">{{ __('Nota (opcional)') }}</label>
            <input id="enlace-nota" name="nota" maxlength="500" value="{{ old('nota') }}"
                   class="mt-1 block min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-teal focus:ring-brand-teal">
            @error('nota')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>
    </form>
@endif

@if ($enlaces->isEmpty())
    <div class="rounded-xl border border-dashed border-brand-green/25 bg-white p-8 text-center text-sm text-gray-600 shadow-sm shadow-brand-green/5">
        {{ __('Aún no hay enlaces en esta sección.') }}
    </div>
@else
    <ul class="divide-y divide-gray-100 overflow-hidden rounded-xl border border-brand-green/10 bg-white shadow-sm shadow-brand-green/5">
        @foreach ($enlaces as $enlace)
            <li class="px-4 py-3" x-data="{ editando: false }">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between" x-show="! editando">
                    <div class="min-w-0">
                        <a href="{{ $enlace->url }}" target="_blank" rel="noopener noreferrer"
                           class="break-words text-sm font-medium text-brand-teal hover:underline">{{ $enlace->titulo }}</a>
                        <p class="truncate text-xs text-gray-500">{{ $enlace->dominio() }} · {{ $enlace->created_at->format('d/m/Y') }}</p>
                        @if ($enlace->nota)
                            <p class="mt-0.5 text-xs text-gray-600">{{ $enlace->nota }}</p>
                        @endif
                    </div>
                    @if ($puedeGestionar)
                        <div class="flex flex-wrap items-center gap-1">
                            <button type="button" @click="editando = true" class="min-h-[36px] rounded-md px-2 text-sm font-medium text-brand-teal hover:bg-brand-mist">{{ __('Editar') }}</button>
                            <x-confirmar-eliminar :action="route('secciones.enlaces.destroy', [$seccion, $enlace])" />
                        </div>
                    @endif
                </div>

                @if ($puedeGestionar)
                    <form method="POST" action="{{ route('secciones.enlaces.update', [$seccion, $enlace]) }}" x-show="editando" x-cloak class="grid grid-cols-1 gap-3 sm:grid-cols-6">
                        @csrf
                        @method('PUT')
                        <div class="sm:col-span-2">
                            <label for="enlace-titulo-{{ $enlace->id }}" class="block text-xs font-medium text-gray-700">{{ __('Título') }}</label>
                            <input id="enlace-titulo-{{ $enlace->id }}" name="titulo" required maxlength="160" value="{{ $enlace->titulo }}"
                                   class="mt-1 block min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm">
                        </div>
                        <div class="sm:col-span-4">
                            <label for="enlace-url-{{ $enlace->id }}" class="block text-xs font-medium text-gray-700">{{ __('Dirección') }}</label>
                            <input id="enlace-url-{{ $enlace->id }}" name="url" type="url" required maxlength="2000" value="{{ $enlace->url }}"
                                   class="mt-1 block min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm">
                        </div>
                        <div class="sm:col-span-6">
                            <label for="enlace-nota-{{ $enlace->id }}" class="block text-xs font-medium text-gray-700">{{ __('Nota') }}</label>
                            <input id="enlace-nota-{{ $enlace->id }}" name="nota" maxlength="500" value="{{ $enlace->nota }}"
                                   class="mt-1 block min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm">
                        </div>
                        <div class="flex gap-2 sm:col-span-6 sm:justify-end">
                            <button type="button" @click="editando = false" class="min-h-[44px] rounded-md px-3 text-sm text-gray-700 hover:bg-gray-100">{{ __('Cancelar') }}</button>
                            <x-primary-button>{{ __('Guardar') }}</x-primary-button>
                        </div>
                    </form>
                @endif
            </li>
        @endforeach
    </ul>
@endif
