<x-app-layout>
    <x-slot name="header">
        <h2 class="font-heading text-xl font-semibold leading-tight text-brand-green sm:text-2xl">
            {{ Auth::user()->estacion?->nombre ?? __('Inicio') }}
        </h2>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">

            <div class="rounded-xl border border-l-4 border-brand-green/10 border-l-brand-teal bg-white p-5 shadow-sm shadow-brand-green/5 sm:p-6">
                <p class="text-sm text-gray-600">{{ __('Sesión activa') }}</p>
                <p class="mt-1 font-heading text-lg font-semibold text-brand-green">
                    {{ __('Bienvenido(a),') }} {{ Auth::user()->name }}
                </p>
            </div>

            @if ($inicio['asistencia'])
                <a href="{{ route('asistencia.captura.index') }}"
                   class="block rounded-xl border border-brand-green/10 bg-white p-5 shadow-sm shadow-brand-green/5 hover:bg-brand-mist focus:outline-none focus:ring-2 focus:ring-brand-teal">
                    <p class="font-heading text-base font-semibold text-brand-green">{{ __('Captura de asistencia de hoy') }}</p>
                    <p class="mt-1 text-sm {{ $inicio['asistencia']['capturado'] ? 'text-brand-teal' : 'text-amber-700' }}">
                        @if ($inicio['asistencia']['capturado'])
                            {{ __('Capturada') }} ({{ $inicio['asistencia']['total_capturado'] }}/{{ $inicio['asistencia']['total_roster'] }})
                        @else
                            {{ __('Pendiente') }} ({{ $inicio['asistencia']['total_capturado'] }}/{{ $inicio['asistencia']['total_roster'] }})
                        @endif
                    </p>
                </a>
            @endif

            <div>
                <h3 class="mb-3 font-heading text-sm font-semibold uppercase tracking-wide text-gray-600">{{ __('Secciones para tu estación') }}</h3>

                @forelse ($inicio['secciones'] as $bloque)
                    <div class="mb-5">
                        <h4 class="mb-2 text-sm font-semibold text-brand-green">{{ $bloque['grupo']->nombre }}</h4>
                        <ul class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            @foreach ($bloque['secciones'] as $seccion)
                                <li>
                                    <a href="{{ route('secciones.show', $seccion) }}"
                                       class="block rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 hover:bg-brand-mist focus:outline-none focus:ring-2 focus:ring-brand-teal">
                                        <p class="font-medium text-gray-900">{{ $seccion->nombre }}</p>
                                        <p class="mt-1 text-xs text-gray-500">
                                            {{ trans_choice('{0} Sin registros|{1} :count registro|[2,*] :count registros', $seccion->filas_count) }}
                                            · {{ trans_choice('{0} Sin documentos|{1} :count documento|[2,*] :count documentos', $seccion->archivos_count) }}
                                        </p>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @empty
                    <p class="rounded-xl border border-dashed border-brand-green/25 bg-white p-6 text-sm text-gray-600">
                        {{ __('Aún no hay secciones habilitadas para tu estación. Cuando el Jefe de Zona apruebe alguna, aparecerá aquí.') }}
                    </p>
                @endforelse
            </div>
        </div>
    </div>
</x-app-layout>
