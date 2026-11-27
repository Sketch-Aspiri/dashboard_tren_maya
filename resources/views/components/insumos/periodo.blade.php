@props(['ruta', 'anio', 'mes', 'parametros' => []])

{{-- Selector de mes: flechas al mes anterior/siguiente, o elegir mes y año y
     pulsar "Ver" (elegir no cambia de pantalla por sí solo: WCAG 3.2.2). --}}
@php
    $meses = [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'];
    $anterior = $mes === 1 ? ['anio' => $anio - 1, 'mes' => 12] : ['anio' => $anio, 'mes' => $mes - 1];
    $siguiente = $mes === 12 ? ['anio' => $anio + 1, 'mes' => 1] : ['anio' => $anio, 'mes' => $mes + 1];
    $flecha = 'inline-flex h-11 w-11 items-center justify-center rounded-md border border-gray-300 bg-white text-gray-600 hover:bg-brand-mist hover:text-brand-green focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal';
    $id = 'periodo-'.\Illuminate\Support\Str::random(4);
@endphp

<form method="GET" action="{{ route($ruta, $parametros) }}" {{ $attributes->merge(['class' => 'flex max-w-full flex-wrap items-end gap-2']) }}>
    <a href="{{ route($ruta, $parametros + $anterior) }}" class="{{ $flecha }}" aria-label="{{ __('Mes anterior') }}: {{ $meses[$anterior['mes']] }} {{ $anterior['anio'] }}"><span aria-hidden="true">‹</span></a>
    <div>
        <label for="{{ $id }}-mes" class="sr-only">{{ __('Mes') }}</label>
        <select id="{{ $id }}-mes" name="mes" class="ins-campo h-11 w-36">
            @foreach ($meses as $numero => $nombre)
                <option value="{{ $numero }}" @selected($numero === $mes)>{{ $nombre }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="{{ $id }}-anio" class="sr-only">{{ __('Año') }}</label>
        <input id="{{ $id }}-anio" name="anio" type="number" class="ins-campo h-11 w-24" value="{{ $anio }}" min="2000" max="2100">
    </div>
    <button type="submit" class="ins-btn-secundario">{{ __('Ver') }}</button>
    <a href="{{ route($ruta, $parametros + $siguiente) }}" class="{{ $flecha }}" aria-label="{{ __('Mes siguiente') }}: {{ $meses[$siguiente['mes']] }} {{ $siguiente['anio'] }}"><span aria-hidden="true">›</span></a>
    <noscript><button type="submit" class="ins-btn-neutro">{{ __('Ver mes') }}</button></noscript>
</form>
