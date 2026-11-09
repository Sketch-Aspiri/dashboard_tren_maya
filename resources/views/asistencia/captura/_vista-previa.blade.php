{{-- Hoja A4 que se dibuja con lo que va en pantalla, guardado o no. Las medidas imitan a GeneradorOficioAsistencia. --}}
<div class="rounded-xl border border-brand-green/10 bg-gray-100 shadow-sm shadow-brand-green/5">
    <div class="flex items-center justify-between gap-2 border-b border-gray-200 bg-white px-4 py-2">
        <h3 class="font-heading text-sm font-semibold text-brand-green">{{ __('Vista previa del oficio') }}</h3>
        <p class="text-xs text-gray-500">{{ __('Clic en un renglón para editarlo. Los saltos de página salen al generar el PDF.') }}</p>
    </div>

    <div x-ref="panelPrevia" class="overflow-y-auto p-3 xl:max-h-[calc(100vh-6rem)]">
        <div x-ref="marcoHoja" class="w-full">
            <div class="of-hoja" :style="`zoom: ${zoomHoja}`">
                <img src="{{ asset('oficio/membrete.png') }}" alt="" class="of-imagen">

                <p class="of-derecha" x-text="`Tjta. No. ${oficio.numero}`"></p>
                <p class="of-titulo" x-text="formato.titulo"></p>
                <p class="of-destino" x-text="formato.destinatario"></p>
                <p class="of-centro" x-text="oficioArmado.fecha"></p>
                <p class="of-parrafo" x-text="oficioArmado.parrafo"></p>

                <p class="of-seccion">A.&nbsp;&nbsp; CONTROL DE LICENCIAS MEDICAS, COMISIONES, INCIDENCIAS, AUSENTISMOS, VACACIONES, BAJA Y NUEVOS INGRESOS.</p>

                <template x-for="resumen in oficioArmado.resumenes" :key="resumen.letra">
                    <div>
                        <p class="of-sub" x-text="`${resumen.letra}   ${resumen.titulo}`"></p>
                        <table class="of-tabla of-resumen">
                            <thead>
                                <tr>
                                    @foreach (['DESCANSO', 'DIA NO LABORABLE', 'LICENCIAS MEDICAS', 'COMISION', 'AVISO DE INCIDENCIA', 'REPORTE DE AUSENTISMO', 'VACACIONES', 'BAJAS', 'NUEVOS INGRESOS', 'PRESENTE'] as $columna)
                                        <th>{{ $columna }}</th>
                                    @endforeach
                                    <th x-text="resumen.total"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><template x-for="(valor, indice) in resumen.valores" :key="indice"><td x-text="valor"></td></template></tr>
                            </tbody>
                        </table>
                    </div>
                </template>

                <p class="of-sub">d)&nbsp;&nbsp; TOTAL, PLAZAS PERMANENTES, EVENTUALES Y DE OTRAS COORDINACIONES COMISIONADOS</p>
                <table class="of-tabla of-resumen">
                    <thead>
                        <tr>
                            @foreach (['DESCANSO', 'DIA NO LABORABLE', 'LICENCIAS MEDICAS'] as $columna)
                                <th rowspan="2">{{ $columna }}</th>
                            @endforeach
                            <th colspan="3">COMISION</th>
                            @foreach (['AVISO DE INCIDENCIA', 'REPORTE DE AUSENTISMO', 'VACACIONES', 'BAJAS', 'NUEVOS INGRESOS', 'PRESENTE', 'TOTAL COMISION'] as $columna)
                                <th rowspan="2">{{ $columna }}</th>
                            @endforeach
                        </tr>
                        <tr><th>PERMANENTE</th><th>EVENTUAL</th><th>OTRA COORD.</th></tr>
                    </thead>
                    <tbody>
                        <tr><template x-for="(valor, indice) in oficioArmado.comisionados" :key="indice"><td x-text="valor"></td></template></tr>
                    </tbody>
                </table>

                <p class="of-seccion">B. RELACIÓN DEL PERSONAL MILITAR ACTIVO QUE SE ENCUENTRA EN CUALQUIERA DE ESTOS SUPUESTOS.</p>
                @include('asistencia.captura._vista-previa-lista', ['filas' => 'oficioArmado.militares', 'conDireccion' => false])

                <p class="of-seccion">C. RELACIÓN DEL PERSONAL CIVIL PRESENTE, CON LICENCIA MEDICA, COMISION, AVISO DE INCIDENCIA, REPORTE DE AUSENTISMO, VACACIONES O BAJA.</p>
                @include('asistencia.captura._vista-previa-lista', ['filas' => 'oficioArmado.permanentes', 'conDireccion' => true])

                <p class="of-seccion">D. RELACIÓN DEL PERSONAL EVENTUAL PRESENTE, CON LICENCIA MEDICA, COMISION, AVISO DE INCIDENCIA, REPORTE DE AUSENTISMO, VACACIONES O BAJA.</p>
                @include('asistencia.captura._vista-previa-lista', ['filas' => 'oficioArmado.eventuales', 'conDireccion' => true])

                <p class="of-seccion">E. RELACIÓN DEL PERSONAL COMISIONADO PRESENTE, CON LICENCIA MEDICA, COMISION, AVISO DE INCIDENCIA, REPORTE DE AUSENTISMO, VACACIONES O BAJA.</p>
                <template x-for="grupo in oficioArmado.seccionE" :key="grupo.titulo">
                    <div>
                        <p class="of-sub of-negrita" x-text="grupo.titulo"></p>
                        @include('asistencia.captura._vista-previa-lista', ['filas' => 'grupo.filas', 'conDireccion' => true])
                    </div>
                </template>

                <div class="of-cierre">
                    <p x-text="formato.despedida"></p>
                    <p x-text="oficio.cargo"></p>
                    <div class="of-firma">
                        <img x-show="firmaUrl" x-cloak :src="firmaUrl" alt="{{ __('Firma digital') }}">
                    </div>
                    <p x-text="oficio.nombre"></p>
                </div>
                <p class="of-iniciales" x-text="oficio.iniciales"></p>

                <img src="{{ asset('oficio/pie.png') }}" alt="" class="of-imagen of-pie">
            </div>
        </div>
    </div>
</div>
