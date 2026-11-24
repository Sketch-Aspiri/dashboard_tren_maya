@php
    /** @var \Illuminate\Pagination\LengthAwarePaginator $notificaciones */
    $modulos = ['insumos' => __('Insumos'), 'asistencia' => __('Asistencia'), 'horarios' => __('Horarios'), 'vacaciones' => __('Vacaciones'), 'cumpleanos' => __('Cumpleaños'), 'ptar' => __('PTAR'), 'otro' => __('Otros')];
    $enlaceFiltro = fn (array $cambios) => route('notificaciones.index', array_filter(array_merge(['estado' => $estado, 'modulo' => $modulo], $cambios), fn ($v) => $v !== null && $v !== 'todas'));
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="min-w-0">
            <h2 class="truncate font-heading text-xl font-semibold leading-tight text-brand-green">{{ __('Notificaciones') }}</h2>
            <p class="mt-0.5 truncate text-sm text-gray-500">{{ __('Avisos de insumos, asistencia y horarios') }}</p>
        </div>
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="mx-auto max-w-4xl space-y-4 px-4 sm:px-6 lg:px-8">

            <div class="flex flex-wrap items-center justify-between gap-3">
                <nav class="flex flex-wrap items-center gap-2" aria-label="{{ __('Filtros') }}">
                    <a href="{{ $enlaceFiltro(['estado' => 'todas']) }}" @if ($estado === 'todas') aria-current="true" @endif
                       class="{{ $estado === 'todas' ? 'ins-chip-marca' : 'ins-chip-neutro' }} hover:underline">{{ __('Todas') }}</a>
                    <a href="{{ $enlaceFiltro(['estado' => 'no-leidas']) }}" @if ($estado === 'no-leidas') aria-current="true" @endif
                       class="{{ $estado === 'no-leidas' ? 'ins-chip-marca' : 'ins-chip-neutro' }} hover:underline">{{ __('No leídas') }} ({{ $sinLeer }})</a>
                    <span class="mx-1 text-gray-300" aria-hidden="true">|</span>
                    <a href="{{ $enlaceFiltro(['modulo' => null]) }}" class="{{ $modulo === null ? 'ins-chip-marca' : 'ins-chip-neutro' }} hover:underline">{{ __('Todos los módulos') }}</a>
                    @foreach ($modulos as $clave => $etiqueta)
                        <a href="{{ $enlaceFiltro(['modulo' => $clave]) }}" @if ($modulo === $clave) aria-current="true" @endif
                           class="{{ $modulo === $clave ? 'ins-chip-marca' : 'ins-chip-neutro' }} hover:underline">{{ $etiqueta }}</a>
                    @endforeach
                </nav>

                @if ($sinLeer > 0)
                    <form method="POST" action="{{ route('notificaciones.read-all') }}">
                        @csrf
                        <button type="submit" class="ins-btn-secundario">{{ __('Marcar todas como leídas') }}</button>
                    </form>
                @endif
            </div>

            <div class="ins-card divide-y divide-gray-100">
                @forelse ($notificaciones as $n)
                    @php($d = $n->data)
                    <a href="{{ route('notificaciones.open', $n->id) }}"
                       class="flex min-h-[44px] items-start gap-3 px-4 py-3 hover:bg-brand-mist focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-teal">
                        <span class="{{ $n->read_at ? 'bg-transparent' : 'bg-brand-teal' }} mt-2 h-2.5 w-2.5 shrink-0 rounded-full" aria-hidden="true"></span>
                        <span class="min-w-0 flex-1">
                            <span class="{{ $n->read_at ? 'font-medium text-gray-700' : 'font-semibold text-brand-green' }} block">{{ $d['titulo'] ?? '' }}
                                @unless ($n->read_at)<span class="sr-only">({{ __('no leída') }})</span>@endunless
                            </span>
                            <span class="block text-sm text-gray-600">{{ $d['cuerpo'] ?? '' }}</span>
                            <span class="mt-1 flex flex-wrap items-center gap-2 text-xs text-gray-500">
                                <span class="ins-chip-info">{{ $modulos[$d['modulo'] ?? 'otro'] ?? $modulos['otro'] }}</span>
                                <time datetime="{{ $n->created_at->toIso8601String() }}">{{ $n->created_at->setTimezone('America/Cancun')->format('d/m/Y H:i') }}</time>
                            </span>
                        </span>
                    </a>
                @empty
                    <p class="px-4 py-8 text-center text-sm text-gray-500">{{ __('No tienes notificaciones.') }}</p>
                @endforelse
            </div>

            {{ $notificaciones->links() }}

            <x-avisos-tickets />
        </div>
    </div>
</x-app-layout>
