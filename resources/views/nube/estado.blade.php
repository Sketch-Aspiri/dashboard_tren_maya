<x-app-layout>
    <x-slot name="header">
        <h2 class="font-heading text-xl font-semibold leading-tight text-brand-green">{{ __('Estado de sincronización') }}</h2>
    </x-slot>

    <div class="py-6 sm:py-10">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            @include('secciones.partials.mensajes')

            {{-- Orígenes --}}
            <section class="rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5" aria-labelledby="titulo-origenes-estado">
                <h3 id="titulo-origenes-estado" class="font-heading text-base font-semibold text-brand-green">{{ __('Orígenes vinculados') }}</h3>

                @forelse ($origenes as $origen)
                    @php
                        $resumen = $origen->resumen ?? [];
                        $omitidos = collect($resumen['omitidos'] ?? [])->map(fn ($n, $motivo) => $n.' · '.__(\App\Enums\MotivoOmision::tryFrom($motivo)?->label() ?? $motivo))->join('; ');
                        $truncado = isset($resumen['truncado']) ? \App\Enums\MotivoTruncado::tryFrom($resumen['truncado']) : null;
                        $retenidos = $origen->mapeos->filter(fn ($mapeo) => $mapeo->estaRetenido());
                    @endphp
                    <div class="border-t border-gray-100 py-3 first:border-t-0">
                        <div class="flex flex-col gap-2 lg:flex-row lg:items-start lg:justify-between">
                            <div class="min-w-0 space-y-0.5">
                                <p class="flex flex-wrap items-center gap-2 text-sm font-medium text-gray-900">
                                    <a href="{{ route('secciones.show', $origen->seccion) }}" class="truncate text-brand-teal hover:underline">{{ $origen->seccion->nombre }}</a>
                                    <span class="text-gray-500">·</span>
                                    <span class="truncate">{{ $origen->nombre }}</span>
                                    <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $origen->estado->insignia() }}">{{ __($origen->estado->label()) }}</span>
                                </p>
                                <p class="text-xs text-gray-600">
                                    {{ $origen->nombreProveedor() }} · {{ $origen->cuenta ? $origen->cuenta->cuenta_email : __('Enlace público') }} ·
                                    {{ $origen->sincronizado_en ? __('revisado :cuando', ['cuando' => $origen->sincronizado_en->diffForHumans()]) : __('aún sin revisar') }}
                                </p>
                                <p class="text-xs text-gray-600">
                                    @isset($resumen['archivos']){{ trans_choice('{1} :count documento|[0,*] :count documentos', $resumen['archivos']) }} · @endisset
                                    {{ trans_choice('{0} sin tablas espejo|{1} :count tabla espejo|[2,*] :count tablas espejo', $origen->mapeos_count) }}
                                </p>
                                @if ($omitidos !== '')
                                    <p class="text-xs text-gray-600">{{ __('Omitidos') }}: {{ $omitidos }}</p>
                                @endif
                                @if ($truncado)
                                    <p class="text-xs text-amber-800">{{ __('Listado incompleto') }}: {{ __($truncado->mensaje()) }}</p>
                                @endif
                                @if ($origen->sincronizacion_error)
                                    <p class="text-xs text-red-700">{{ $origen->sincronizacion_error }}</p>
                                @endif
                            </div>
                            <div class="flex flex-wrap items-center gap-1 lg:justify-end">
                                <form method="POST" action="{{ route('secciones.origenes.sincronizar', [$origen->seccion, $origen]) }}">
                                    @csrf
                                    <button type="submit" class="min-h-[44px] rounded-md px-3 text-sm font-medium text-brand-teal hover:bg-brand-mist focus:outline-none focus:ring-2 focus:ring-brand-teal">{{ __('Sincronizar ahora') }}</button>
                                </form>
                                @if ($origen->mapeos_count > 0)
                                    <a href="{{ route('secciones.origenes.mapeo.edit', [$origen->seccion, $origen]) }}" class="inline-flex min-h-[44px] items-center rounded-md px-3 text-sm font-medium text-gray-700 hover:bg-gray-100">{{ __('Editar mapeo') }}</a>
                                @endif
                            </div>
                        </div>
                        @if ($origen->estado === \App\Enums\EstadoOrigen::PendienteMapeo)
                            <p class="mt-2 rounded-md bg-amber-50 p-2 text-xs text-amber-900" role="status">
                                {{ __('Falta confirmar el mapeo de columnas de un Excel. Los documentos se sincronizan; las tablas esperan.') }}
                                <a href="{{ route('secciones.show', [$origen->seccion, 'tab' => 'cargas']) }}" class="font-semibold underline">{{ __('Ir a la sección') }}</a>
                            </p>
                        @endif
                        @foreach ($retenidos as $retenido)
                            <x-retenido-mapeo :mapeo="$retenido" :origen="$origen" class="mt-2" />
                        @endforeach
                    </div>
                @empty
                    <p class="mt-3 text-sm text-gray-600">{{ __('Aún no hay carpetas ni archivos vinculados a la nube.') }}</p>
                @endforelse
            </section>

            {{-- Cuentas --}}
            <section class="rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5" aria-labelledby="titulo-cuentas-estado">
                <h3 id="titulo-cuentas-estado" class="font-heading text-base font-semibold text-brand-green">{{ __('Cuentas conectadas') }}</h3>
                <ul class="mt-2 divide-y divide-gray-100 text-sm">
                    @forelse ($cuentas as $cuenta)
                        <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                            <span>{{ $cuenta->proveedor->etiquetaCorta() }} · {{ $cuenta->cuenta_email }}</span>
                            <span class="flex items-center gap-2">
                                @if ($cuenta->ultimo_error)
                                    <span class="text-xs text-red-700">{{ \Illuminate\Support\Str::limit($cuenta->ultimo_error, 120) }}</span>
                                @endif
                                <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $cuenta->estaActiva() ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800' }}">{{ __($cuenta->estado->label()) }}</span>
                            </span>
                        </li>
                    @empty
                        <li class="py-2 text-gray-600">{{ __('No hay cuentas conectadas.') }}</li>
                    @endforelse
                </ul>
                @can('viewAny', \App\Models\NubeCuenta::class)
                    <a href="{{ route('nube.cuentas.index') }}" class="mt-2 inline-flex min-h-[44px] items-center text-sm font-semibold text-brand-teal underline">{{ __('Conexiones de nube') }}</a>
                @endcan
            </section>

            {{-- Fallos recientes --}}
            <section class="rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5" aria-labelledby="titulo-fallos-estado">
                <h3 id="titulo-fallos-estado" class="font-heading text-base font-semibold text-brand-green">{{ __('Trabajos fallidos recientes') }}</h3>
                <p class="text-xs text-gray-600">{{ __('Los últimos 20 trabajos de sincronización que fallaron. El detalle está en el registro del servidor.') }}</p>
                <ul class="mt-2 divide-y divide-gray-100 text-sm">
                    @forelse ($fallos as $fallo)
                        <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                            <span class="font-mono text-xs">#{{ $fallo['id'] }} · {{ $fallo['cola'] }} · {{ $fallo['clase'] }}</span>
                            <span class="text-xs text-gray-600">{{ $fallo['fallo_en'] }}</span>
                        </li>
                    @empty
                        <li class="py-2 text-gray-600">{{ __('Sin fallos recientes.') }}</li>
                    @endforelse
                </ul>
            </section>
        </div>
    </div>
</x-app-layout>
