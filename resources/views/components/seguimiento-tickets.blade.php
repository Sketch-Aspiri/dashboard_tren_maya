{{-- «Seguimiento en Tickets: ACT-2026-0012» junto al registro que originó la actividad (ecosistema Zona Oriente).
     Folio y enlace los guarda EnviarActividadTickets tras validar su formato; aquí se vuelve a comprobar que el
     enlace sea una actividad de Tickets antes de imprimirlo como href (defensa en profundidad: nunca un esquema
     distinto ni otro dominio). Todo se imprime escapado. Sin vínculos no muestra nada. --}}
@props(['modelo'])

@php
    $vinculos = $modelo->vinculosTickets;
    $baseTickets = rtrim((string) config('ecosistema.urls.tickets'), '/').'/activities/';
@endphp

@if ($vinculos->isNotEmpty())
    <div class="rounded-lg border border-brand-teal/30 bg-brand-mist px-4 py-3 text-sm text-gray-800" role="note">
        <p class="font-semibold text-brand-green">{{ __('Seguimiento en Tickets') }}</p>
        <ul class="mt-1 space-y-1">
            @foreach ($vinculos as $vinculo)
                <li>
                    @if (str_starts_with((string) $vinculo->url, $baseTickets) && preg_match('/^\d{1,12}$/D', substr((string) $vinculo->url, strlen($baseTickets))) === 1)
                        <a href="{{ $vinculo->url }}" class="inline-flex min-h-[44px] items-center gap-1 font-semibold text-brand-teal hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">
                            {{ $vinculo->folio }} <span aria-hidden="true">→</span>
                        </a>
                    @else
                        <span class="font-semibold text-gray-700">{{ $vinculo->folio }}</span>
                    @endif
                    <span class="text-gray-600">· {{ $vinculo->created_at->timezone('America/Cancun')->format('d/m/Y') }}</span>
                </li>
            @endforeach
        </ul>
    </div>
@endif
