{{-- Aviso global de la sincronización con la nube: solo Administrador y Jefe de Zona (EstadoSincronizacion::avisos devuelve 0 al resto). --}}
@php
    $avisos = auth()->check() ? app(\App\Services\Nube\EstadoSincronizacion::class)->avisos(auth()->user()) : ['total' => 0];
@endphp
@if ($avisos['total'] > 0)
    <div x-show="! open" class="border-b border-amber-300 bg-amber-50" role="alert">
        <div class="mx-auto flex max-w-7xl flex-col gap-1 px-4 py-2 text-sm text-amber-900 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
            <span>
                {{ __('Sincronización con la nube:') }}
                @if ($avisos['cuentas'] > 0)
                    {{ trans_choice('{1} :count cuenta necesita reconectarse.|[2,*] :count cuentas necesitan reconectarse.', $avisos['cuentas']) }}
                @endif
                @if ($avisos['mapeos'] > 0)
                    {{ trans_choice('{1} :count origen espera confirmar el mapeo de un Excel.|[2,*] :count orígenes esperan confirmar el mapeo de un Excel.', $avisos['mapeos']) }}
                @endif
                @if ($avisos['retenidos'] > 0)
                    {{ trans_choice('{1} :count cambio masivo retenido.|[2,*] :count cambios masivos retenidos.', $avisos['retenidos']) }}
                @endif
            </span>
            <a href="{{ route('nube.estado') }}" class="inline-flex min-h-[44px] items-center font-semibold underline">{{ __('Ver estado de sincronización') }}</a>
        </div>
    </div>
@endif
