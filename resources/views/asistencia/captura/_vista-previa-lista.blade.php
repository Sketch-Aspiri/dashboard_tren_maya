{{-- $filas: expresión Alpine con los renglones; $conDireccion: si lleva la columna DIRECCIÓN. Sin renglones queda una fila baja, como en el Excel. --}}
<table class="of-tabla of-lista">
    <thead>
        <tr>
            <th style="width: 4%">NO.</th>
            @if ($conDireccion)
                <th style="width: 14%">DIRECCIÓN</th>
                <th style="width: 7%">NO. TRAB.</th>
            @else
                <th style="width: 10.5%">NO. TRAB.</th>
            @endif
            <th>NOMBRE</th>
            <th style="width: 23%">ESTATUS</th>
            <th style="width: 23%">UBICACIÓN ACTUAL</th>
        </tr>
    </thead>
    <tbody>
        <template x-for="(fila, indice) in {{ $filas }}" :key="fila.clave">
            <tr :data-fila="fila.clave" @click="irAFila(fila)" class="of-renglon" :class="resaltado === fila.clave && 'of-resaltado'">
                <td class="of-c" x-text="indice + 1"></td>
                @if ($conDireccion)
                    <td class="of-c of-dir" x-text="fila.dir"></td>
                @endif
                <td class="of-c" x-text="fila.trab"></td>
                <td :class="fila.negrita && 'of-negrita'" x-text="fila.nombre"></td>
                <td class="of-c" :class="[fila.estatusNegrita && 'of-negrita', fila.pendiente && 'of-pendiente']">
                    <template x-for="linea in fila.lineas" :key="linea"><div x-text="linea"></div></template>
                </td>
                <td :class="fila.negrita && 'of-negrita'" x-text="fila.ubic"></td>
            </tr>
        </template>
        <template x-if="{{ $filas }}.length === 0">
            <tr class="of-vacia">
                <td></td>
                @if ($conDireccion)
                    <td></td>
                @endif
                <td></td><td></td><td></td><td></td>
            </tr>
        </template>
    </tbody>
</table>
