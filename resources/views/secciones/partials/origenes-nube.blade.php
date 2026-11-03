{{-- Orígenes de nube de la sección (carpetas o archivos vinculados) y el modal "Vincular desde la nube".
     Solo se incluye para quien gestiona datos (roles de zona). --}}
@php
    $puedeVerConexiones = auth()->user()->can('viewAny', \App\Models\NubeCuenta::class);
    $hayCuentas = $cuentasNube->isNotEmpty();
@endphp

<section class="mb-4 rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5" aria-labelledby="titulo-origenes">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <h3 id="titulo-origenes" class="font-heading text-base font-semibold text-brand-green">{{ __('Orígenes de la sección') }}</h3>
        <div x-data class="flex">
            <button type="button" @click="$dispatch('abrir-vincular-nube')"
                    class="min-h-[44px] rounded-md bg-brand-green px-4 text-sm font-semibold text-white hover:bg-brand-green-dark focus:outline-none focus:ring-2 focus:ring-brand-teal">
                {{ __('Vincular desde la nube') }}
            </button>
        </div>
    </div>

    @if ($cuentasPorReconectar->isNotEmpty())
        <div class="mt-3 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900" role="alert">
            {{ trans_choice('{1} Una cuenta de nube necesita reconectarse|[2,*] :count cuentas de nube necesitan reconectarse', $cuentasPorReconectar->count()) }}:
            {{ $cuentasPorReconectar->pluck('cuenta_email')->join(', ') }}.
            @if ($puedeVerConexiones)
                <a href="{{ route('nube.cuentas.index') }}" class="font-semibold underline">{{ __('Ir a Conexiones de nube') }}</a>
            @else
                {{ __('Pide al Administrador que la reconecte en Conexiones de nube.') }}
            @endif
            {{ __('Mientras tanto sus orígenes no se sincronizan y los documentos se conservan.') }}
        </div>
    @endif

    @if ($origenes->isEmpty())
        <p class="mt-3 text-sm text-gray-600">{{ __('Esta sección aún no tiene carpetas ni archivos vinculados a la nube.') }}</p>
    @else
        <ul class="mt-3 divide-y divide-gray-100">
            @foreach ($origenes as $origen)
                @php
                    $resumen = $origen->resumen ?? [];
                    $omitidosOrigen = $resumen['omitidos'] ?? [];
                    $pausado = $origen->estado === \App\Enums\EstadoOrigen::Pausado;
                @endphp
                <li class="py-3">
                    <div class="flex flex-col gap-2 lg:flex-row lg:items-start lg:justify-between">
                        <div class="min-w-0">
                            <p class="flex flex-wrap items-center gap-2 text-sm font-medium text-gray-900">
                                <span class="truncate">{{ $origen->tipo === 'carpeta' ? __('Carpeta') : __('Archivo') }}: {{ $origen->nombre }}</span>
                                <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $origen->estado->insignia() }}">{{ __($origen->estado->label()) }}</span>
                            </p>
                            <p class="text-xs text-gray-500">
                                {{ $origen->cuenta ? $origen->cuenta->cuenta_email : __('Enlace público') }} ·
                                {{ $origen->sincronizado_en ? __('revisado :cuando', ['cuando' => $origen->sincronizado_en->diffForHumans()]) : __('aún sin revisar') }}
                                @isset($resumen['archivos']) · {{ trans_choice('{1} :count documento|[0,*] :count documentos', $resumen['archivos']) }} @endisset
                            </p>
                            @if ($omitidosOrigen !== [])
                                <p class="text-xs text-gray-600">
                                    {{ __('Omitidos') }}:
                                    {{ collect($omitidosOrigen)->map(fn ($n, $motivo) => $n.' · '.__(\App\Enums\MotivoOmision::tryFrom($motivo)?->label() ?? $motivo))->join('; ') }}
                                </p>
                            @endif
                            @if ($origen->sincronizacion_error)
                                <p class="mt-0.5 text-xs text-amber-700" role="status">{{ $origen->sincronizacion_error }}</p>
                            @endif
                        </div>
                        <div class="flex flex-wrap items-center gap-1 lg:justify-end">
                            <form method="POST" action="{{ route('secciones.origenes.sincronizar', [$seccion, $origen]) }}">
                                @csrf
                                <button type="submit" class="min-h-[44px] rounded-md px-3 text-sm font-medium text-brand-teal hover:bg-brand-mist focus:outline-none focus:ring-2 focus:ring-brand-teal">{{ __('Sincronizar ahora') }}</button>
                            </form>
                            <form method="POST" action="{{ route('secciones.origenes.update', [$seccion, $origen]) }}">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="estado" value="{{ $origen->estado === \App\Enums\EstadoOrigen::Activo ? 'pausado' : 'activo' }}">
                                <button type="submit" class="min-h-[44px] rounded-md px-3 text-sm font-medium text-gray-700 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-brand-teal">
                                    {{ $origen->estado === \App\Enums\EstadoOrigen::Activo ? __('Pausar') : __('Reanudar') }}
                                </button>
                            </form>
                            @if ($resumen['cambio_masivo_retenido'] ?? false)
                                <x-confirmar-eliminar :action="route('secciones.origenes.update', [$seccion, $origen])" metodo="PUT" tono="neutro"
                                                      :etiqueta="__('Confirmar archivado')" :confirmar="__('Sí, archivar')"
                                                      :pregunta="__('La nube no devolvió archivos. Se archivarán todos los documentos de este origen (se pueden restaurar). ¿Continuar?')">
                                    <input type="hidden" name="confirmar_baja_masiva" value="1">
                                </x-confirmar-eliminar>
                            @endif
                            <x-confirmar-eliminar :action="route('secciones.origenes.destroy', [$seccion, $origen])" tono="neutro"
                                                  :etiqueta="__('Desvincular')" :confirmar="__('Sí, desvincular')"
                                                  :pregunta="__('Los documentos se conservan como normales y dejan de actualizarse. ¿Continuar?')">
                                <input type="hidden" name="conservar" value="1">
                            </x-confirmar-eliminar>
                            <x-confirmar-eliminar :action="route('secciones.origenes.destroy', [$seccion, $origen])"
                                                  :etiqueta="__('Desvincular y archivar')" :confirmar="__('Sí, archivar')"
                                                  :pregunta="__('Se desvincula el origen y sus documentos se archivan (ya no se ven). ¿Continuar?')">
                                <input type="hidden" name="conservar" value="0">
                            </x-confirmar-eliminar>
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</section>

{{-- Modal fijo al viewport, como el de filas. --}}
<div x-data="{
        abierto: false, cuenta: '', enlace: '', cargando: false, error: '', resultado: null,
        reiniciar() { this.error = ''; this.resultado = null; },
        async inspeccionar() {
            this.reiniciar();
            this.cargando = true;
            try {
                const respuesta = await fetch(@js(route('secciones.origenes.inspeccionar', $seccion)), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    },
                    body: JSON.stringify({ cuenta_id: this.cuenta || null, enlace: this.enlace }),
                });
                const cuerpo = await respuesta.json().catch(() => ({}));
                if (! respuesta.ok) {
                    const errores = cuerpo.errors || {};
                    this.error = (errores.enlace || errores.cuenta_id || [cuerpo.message || @js(__('No se pudo revisar el enlace.'))])[0];
                    return;
                }
                this.resultado = cuerpo.data;
            } catch (e) {
                this.error = @js(__('No se pudo revisar el enlace. Intenta de nuevo.'));
            } finally {
                this.cargando = false;
            }
        },
        resumen() {
            const r = this.resultado;
            if (! r) { return ''; }
            const partes = [r.archivos + (r.archivos === 1 ? @js(__(' documento')) : @js(__(' documentos'))), r.excel + @js(__(' Excel'))];
            if (r.omitidos_total > 0) {
                partes.push(r.omitidos_total + @js(__(' omitidos')) + ' (' + r.omitidos.map(o => o.etiqueta + ': ' + o.cantidad).join(', ') + ')');
            }
            return (r.tipo === 'carpeta' ? @js(__('Carpeta')) : @js(__('Archivo'))) + ' ' + r.nombre + ': ' + partes.join(', ');
        },
     }"
     @abrir-vincular-nube.window="abierto = true; $nextTick(() => $refs.enlace.focus())"
     @keydown.escape.window="abierto = false">
    <div x-show="abierto" x-cloak
         class="fixed inset-0 z-50 flex items-end justify-center bg-gray-900/50 p-0 sm:items-center sm:p-4"
         role="dialog" aria-modal="true" aria-labelledby="titulo-vincular-nube"
         @click.self="abierto = false">
        <form method="POST" action="{{ route('secciones.origenes.store', $seccion) }}"
              class="max-h-[90vh] w-full max-w-lg space-y-3 overflow-y-auto rounded-t-2xl border border-gray-200 bg-white p-5 shadow-xl sm:rounded-2xl sm:p-5"
              @click.stop @submit="if (! resultado) { $event.preventDefault(); inspeccionar(); }">
            @csrf
            <div class="flex items-start justify-between gap-3">
                <h3 id="titulo-vincular-nube" class="font-heading text-base font-semibold text-brand-green">{{ __('Vincular desde la nube') }}</h3>
                <button type="button" @click="abierto = false" class="min-h-[44px] min-w-[44px] rounded-md text-gray-500 hover:bg-gray-100" aria-label="{{ __('Cerrar') }}">✕</button>
            </div>

            @if (! $hayCuentas && ! $hayConexionesNube)
                <p class="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                    {{ __('Aún no hay ninguna cuenta de nube conectada. Para vincular carpetas, el Administrador debe configurar las credenciales y conectar una cuenta en Conexiones de nube.') }}
                    @if ($puedeVerConexiones)
                        <a href="{{ route('nube.cuentas.index') }}" class="font-semibold underline">{{ __('Ir a Conexiones de nube') }}</a>
                    @endif
                    {{ __('Mientras tanto puedes vincular un archivo suelto con su enlace público.') }}
                </p>
            @elseif (! $hayCuentas)
                <p class="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                    {{ __('Hay credenciales, pero ninguna cuenta conectada y activa. El Administrador debe conectar una cuenta en Conexiones de nube.') }}
                    @if ($puedeVerConexiones)
                        <a href="{{ route('nube.cuentas.index') }}" class="font-semibold underline">{{ __('Ir a Conexiones de nube') }}</a>
                    @endif
                </p>
            @endif

            <div>
                <label for="origen-cuenta" class="block text-xs font-medium text-gray-600">{{ __('Cuenta de nube') }}</label>
                <select id="origen-cuenta" name="cuenta_id" x-model="cuenta" @change="reiniciar()"
                        class="mt-1 min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-teal focus:ring-brand-teal">
                    <option value="">{{ __('Enlace público (solo archivos)') }}</option>
                    @foreach ($cuentasNube as $cuenta)
                        <option value="{{ $cuenta->id }}">{{ $cuenta->proveedor->label() }} · {{ $cuenta->cuenta_email }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="origen-enlace" class="block text-xs font-medium text-gray-600">{{ __('Enlace compartido (Google Drive, OneDrive, SharePoint o Dropbox)') }}</label>
                <input x-ref="enlace" id="origen-enlace" name="enlace" type="url" required maxlength="2000" placeholder="https://"
                       x-model="enlace" @input="reiniciar()"
                       class="mt-1 min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-teal focus:ring-brand-teal">
                <p class="mt-1 text-xs text-gray-500">{{ __('Con una cuenta conectada puedes vincular carpetas completas. Solo se lee: nada se modifica en la nube.') }}</p>
            </div>

            <div aria-live="polite" class="text-sm">
                <p x-show="error" x-cloak x-text="error" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-red-800" role="alert"></p>
                <p x-show="resultado" x-cloak x-text="resumen()" class="rounded-md border border-green-200 bg-green-50 px-3 py-2 text-green-900"></p>
            </div>

            <div x-show="resultado" x-cloak class="space-y-2">
                <input type="hidden" name="alimenta_tablas" value="0">
                @unless ($seccion->solo_documentos)
                    <label class="flex min-h-[44px] items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" name="alimenta_tablas" value="1" checked class="h-5 w-5 rounded border-gray-300 text-brand-teal focus:ring-brand-teal">
                        {{ __('Actualizar también las tablas de la sección con los Excel') }}
                    </label>
                @endunless
                <input type="hidden" name="incluir_subcarpetas" value="0">
                <label class="flex min-h-[44px] items-center gap-2 text-sm text-gray-700" x-show="resultado && resultado.tipo === 'carpeta'">
                    <input type="checkbox" name="incluir_subcarpetas" value="1" checked class="h-5 w-5 rounded border-gray-300 text-brand-teal focus:ring-brand-teal">
                    {{ __('Incluir subcarpetas') }}
                </label>
            </div>

            <div class="flex flex-col gap-2 sm:flex-row-reverse">
                <button type="button" x-show="! resultado" @click="inspeccionar()" :disabled="cargando || enlace === ''"
                        class="min-h-[44px] w-full rounded-md bg-brand-green px-4 text-sm font-semibold text-white disabled:opacity-50 sm:w-auto">
                    <span x-show="! cargando">{{ __('Inspeccionar') }}</span><span x-show="cargando" x-cloak>{{ __('Revisando…') }}</span>
                </button>
                <button type="submit" x-show="resultado" x-cloak class="min-h-[44px] w-full rounded-md bg-brand-green px-4 text-sm font-semibold text-white sm:w-auto">{{ __('Confirmar y vincular') }}</button>
                <button type="button" @click="abierto = false" class="min-h-[44px] w-full rounded-md text-sm font-medium text-gray-600 hover:bg-gray-100 sm:w-auto sm:px-4">{{ __('Cancelar') }}</button>
            </div>
        </form>
    </div>
</div>
