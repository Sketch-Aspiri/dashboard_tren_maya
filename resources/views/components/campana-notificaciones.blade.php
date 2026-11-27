@props(['movil' => false])

{{-- Campana de notificaciones: enlace a la bandeja con insignia de no leídas (sondeo cada minuto). --}}
<a href="{{ route('notificaciones.index') }}"
   x-data="notificacionesContador(@js(route('notificaciones.summary')), {{ (int) auth()->user()->unreadNotifications()->count() }})"
   {{ $attributes->class([
       'relative inline-flex min-h-[44px] items-center justify-center rounded-md text-brand-green hover:bg-brand-mist focus:outline-none focus:ring-2 focus:ring-brand-teal',
       'min-w-[44px] px-2' => ! $movil,
       'gap-2 px-3' => $movil,
   ]) }}
   aria-label="{{ __('Notificaciones') }}"
   :aria-label="hayPendientes ? {{ \Illuminate\Support\Js::from(__('Notificaciones, sin leer:').' ') }} + etiqueta : {{ \Illuminate\Support\Js::from(__('Notificaciones')) }}">
    <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0"/>
    </svg>
    @if ($movil)<span class="text-base font-medium">{{ __('Notificaciones') }}</span>@endif
    <span x-show="hayPendientes" x-cloak x-text="etiqueta" aria-hidden="true"
          class="{{ $movil ? '' : 'absolute -right-0.5 -top-0.5' }} inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-red-700 px-1.5 text-xs font-semibold leading-5 text-white"></span>
</a>
