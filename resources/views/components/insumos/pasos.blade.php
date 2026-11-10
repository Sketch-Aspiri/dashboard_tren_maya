@props(['estado'])

{{-- Recorrido de una solicitud: en qué paso va y qué sigue. "Observada"
     regresa al primer paso (la estación corrige y vuelve a enviar). --}}
@php
    $pasos = [
        ['Captura', __('La estación captura y envía')],
        ['Revisión de Zona', __('Zona pone precios y aprueba')],
        ['Coordinación', __('Se envía el ANEXO con oficio')],
        ['Respuesta', __('La Coordinación devuelve el Excel')],
    ];
    $actual = ['borrador' => 0, 'observada' => 0, 'enviada' => 1, 'aprobada' => 2, 'enviada_coordinacion' => 3, 'devuelta' => 4][$estado] ?? 0;
@endphp

<ol {{ $attributes->merge(['class' => 'ins-card grid grid-cols-2 gap-px overflow-hidden bg-gray-100 lg:grid-cols-4']) }} aria-label="{{ __('Avance de la solicitud') }}">
    @foreach ($pasos as $i => [$nombre, $ayuda])
        @php $hecho = $i < $actual; $enCurso = $i === $actual; @endphp
        <li class="flex items-start gap-3 bg-white p-3 sm:p-4" @if ($enCurso) aria-current="step" @endif>
            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold {{ $hecho ? 'bg-brand-green text-white' : ($enCurso ? 'bg-brand-mist text-brand-green ring-2 ring-brand-teal' : 'bg-gray-100 text-gray-500') }}">
                @if ($hecho)<span aria-hidden="true">✓</span><span class="sr-only">{{ __('Hecho') }}</span>@else{{ $i + 1 }}@endif
            </span>
            <span class="min-w-0">
                <span class="block text-sm font-semibold {{ $hecho || $enCurso ? 'text-brand-green' : 'text-gray-500' }}">{{ __($nombre) }}</span>
                <span class="block text-xs text-gray-500">{{ $ayuda }}</span>
            </span>
        </li>
    @endforeach
</ol>
