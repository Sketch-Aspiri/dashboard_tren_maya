@php
    /** @var \App\Models\Estacion $estacion */
    /** @var array<int, array{abordan: int|null, boletos: int|null}> $dias */
    $nombresMes = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
        7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];
    $diasSemana = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
    $totalAbordan = collect($dias)->sum('abordan');
    $totalBoletos = collect($dias)->sum('boletos');
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-insumos.titulo :titulo="$estacion->nombre.' — '.$nombresMes[$mes].' '.$anio" :seccion="__('Flujo de pasajeros')" :grupo="__('Recursos financieros')"
                          :detalle="__('Abordan y boletos vendidos por día')"
                          :volver="$esZona ? route('afluencia.index', ['anio' => $anio, 'mes' => $mes]) : null" :volver-texto="__('Tablero del mes')" />
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <x-insumos.avisos />
            @if ($errors->any())
                <div class="ins-aviso-error" role="alert">
                    <ul class="list-disc pl-5">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                </div>
            @endif

            <x-insumos.periodo ruta="afluencia.estacion" :anio="$anio" :mes="$mes" :parametros="['estacion' => $estacion->id]" />

            <form method="POST" action="{{ route('afluencia.guardar', $estacion) }}" class="space-y-4">
                @csrf
                @method('PUT')
                <input type="hidden" name="anio" value="{{ $anio }}">
                <input type="hidden" name="mes" value="{{ $mes }}">

                <div class="ins-tabla-marco">
                    <table class="ins-tabla">
                        <caption class="sr-only">{{ __('Captura diaria de :e', ['e' => $estacion->nombre]) }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Día') }}</th>
                                <th scope="col" class="!text-right">{{ __('Abordan') }}</th>
                                <th scope="col" class="!text-right">{{ __('Boletos vendidos') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @for ($d = 1; $d <= $diasMes; $d++)
                                @php
                                    $futuro = $d > $hastaDia;
                                    $v = $dias[$d] ?? null;
                                    $dow = \Carbon\CarbonImmutable::create($anio, $mes, $d)->dayOfWeek;
                                @endphp
                                <tr>
                                    <th scope="row" class="px-3 py-1.5 text-left font-medium {{ $futuro ? 'text-gray-400' : 'text-gray-900' }} sm:px-4">
                                        {{ $d }} <span class="text-xs font-normal text-gray-500">{{ $diasSemana[$dow] }}</span>
                                    </th>
                                    @foreach (['abordan' => __('Abordan'), 'boletos' => __('Boletos vendidos')] as $campo => $etiqueta)
                                        <td class="num">
                                            <label class="sr-only" for="d{{ $d }}-{{ $campo }}">{{ $etiqueta }} {{ __('día') }} {{ $d }}</label>
                                            <input id="d{{ $d }}-{{ $campo }}" name="dias[{{ $d }}][{{ $campo }}]" type="number" inputmode="numeric" min="0" max="99999"
                                                   value="{{ old("dias.$d.$campo", $v[$campo] ?? '') }}" class="ins-campo h-10 w-28 text-right" @disabled($futuro)>
                                        </td>
                                    @endforeach
                                </tr>
                            @endfor
                        </tbody>
                        <tfoot>
                            <tr class="font-semibold">
                                <th scope="row" class="px-3 py-2 text-left sm:px-4">{{ __('Total') }}</th>
                                <td class="num">{{ number_format($totalAbordan) }}</td>
                                <td class="num">{{ number_format($totalBoletos) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <p class="ins-ayuda">{{ __('Deja vacíos los días sin dato: un vacío no es un cero. Los días que aún no llegan están bloqueados.') }}</p>
                <div class="ins-barra-acciones">
                    <button type="submit" class="ins-btn-primario">{{ __('Guardar') }}</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
