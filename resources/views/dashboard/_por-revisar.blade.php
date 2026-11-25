{{-- Lo que las estaciones enviaron y espera a la Zona (App\Services\Pendientes\PendientesZona). --}}
<section class="rounded-xl border border-brand-green/10 bg-white p-5 shadow-sm shadow-brand-green/5 sm:p-6" aria-labelledby="titulo-por-revisar">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h3 id="titulo-por-revisar" class="font-heading text-base font-semibold text-brand-green">{{ __('Por revisar') }}</h3>
        @if ($porRevisar->isNotEmpty())
            <span class="ins-chip-info">{{ trans_choice(':n en espera|:n en espera', $porRevisar->sum('cantidad'), ['n' => $porRevisar->sum('cantidad')]) }}</span>
        @endif
    </div>

    @if ($porRevisar->isEmpty())
        <p class="mt-3 text-sm text-gray-600">{{ __('No hay nada por revisar.') }}</p>
    @else
        <ul class="mt-3 divide-y divide-gray-100">
            @foreach ($porRevisar as $fila)
                <li>
                    <a href="{{ $fila['href'] }}"
                       class="-mx-2 flex min-h-[52px] items-center gap-3 rounded-lg px-2 py-2 hover:bg-brand-mist focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">
                        <span class="min-w-[2.5rem] font-heading text-xl font-bold tabular-nums text-brand-green">{{ $fila['cantidad'] }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-medium text-gray-900">{{ $fila['titulo'] }}</span>
                            @if ($fila['detalle'])
                                <span class="block text-xs text-gray-500">{{ $fila['detalle'] }}</span>
                            @endif
                        </span>
                        <span class="shrink-0 text-sm font-semibold text-brand-teal">{{ __('Revisar') }} <span aria-hidden="true">→</span></span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</section>
