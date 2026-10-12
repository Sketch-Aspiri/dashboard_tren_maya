<?php

namespace Tests\Unit\Secciones;

use App\Services\Secciones\PreparadorTabla;
use PHPUnit\Framework\TestCase;

class PreparadorTablaTest extends TestCase
{
    public function test_suggests_the_header_row_below_institutional_titles(): void
    {
        $filas = [
            ['TREN MAYA — ZONA ORIENTE', null, null],
            ['Relación de personal', null, null],
            ['No. empleado', 'Nombre', 'Estación'],
            [1001, 'Ana López', 'Chetumal'],
        ];

        $this->assertSame(2, (new PreparadorTabla)->filaEncabezadoSugerida($filas));
    }

    public function test_names_blank_and_repeated_headers(): void
    {
        $encabezados = (new PreparadorTabla)->encabezados([['Nombre', null, 'Nombre', 'NOMBRE']], 0);

        $this->assertSame(['Nombre', 'Columna 2', 'Nombre (2)', 'NOMBRE (3)'], $encabezados);
    }

    public function test_drops_repeated_header_rows_from_the_data(): void
    {
        $filas = [
            ['Folio', 'Estatus'],
            ['A-1', 'Abierto'],
            ['Folio', 'Estatus'],
            ['A-2', 'Cerrado'],
        ];

        $this->assertSame([['A-1', 'Abierto'], ['A-2', 'Cerrado']], (new PreparadorTabla)->datos($filas, 0));
    }
}
