{{-- "Notificaciones en tu celular" (ecosistema Zona Oriente): los avisos del Dashboard llegan por la PWA de
     Tickets, donde se instala y se activan las notificaciones. Solo se muestra con el puente configurado. --}}
@if (app(\App\Services\Ecosistema\PuenteCliente::class)->activo())
    <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
        <div class="max-w-xl">
            <h3 class="text-lg font-medium text-gray-900">{{ __('Avisos en tu celular') }}</h3>
            <p class="mt-1 text-sm text-gray-600">
                {{ __('Recibe en tu celular los avisos de insumos y de asistencia. Se activan una sola vez desde Tickets (instálalo en tu pantalla de inicio y pulsa «Activar notificaciones»); necesitas tener tus cuentas unidas.') }}
            </p>
            <a href="{{ config('ecosistema.urls.tickets') }}/notifications"
               class="mt-4 inline-flex min-h-[44px] items-center font-semibold text-brand-teal hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">
                {{ __('Activar notificaciones en Tickets') }} <span aria-hidden="true">&nbsp;→</span>
            </a>
        </div>
    </div>
@endif
