<?php

namespace Tests\Unit\Secciones;

use App\Services\Secciones\Hoja\FormulasHoja;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Fórmulas de la hoja del lado del servidor: ajuste de referencias al
 * insertar/borrar filas y columnas, y traducción a Excel con lista blanca.
 */
class FormulasHojaTest extends TestCase
{
    /**
     * @return array<string, array{string, string, int, int, string}>
     */
    public static function desplazamientos(): array
    {
        return [
            'insertar fila arriba de la referencia' => ['=A3+B1', 'fila', 1, 2, '=A5+B1'],
            'insertar fila dentro de un rango lo agranda' => ['=SUMA(A1:A5)', 'fila', 2, 1, '=SUMA(A1:A6)'],
            'insertar debajo no cambia nada' => ['=SUMA(A1:A5)', 'fila', 5, 3, '=SUMA(A1:A5)'],
            'las fijas también se mueven' => ['=$A$3*2', 'fila', 0, 1, '=$A$4*2'],
            'borrar la fila referida da #¡REF!' => ['=A3+1', 'fila', 2, -1, '=#¡REF!+1'],
            'borrar dentro de un rango lo recorta' => ['=SUMA(A1:A5)', 'fila', 1, -2, '=SUMA(A1:A3)'],
            'borrar todo el rango da #¡REF!' => ['=SUMA(A2:A3)', 'fila', 1, -2, '=SUMA(#¡REF!)'],
            'borrar arriba sube la referencia' => ['=B10', 'fila', 0, -3, '=B7'],
            'insertar columna' => ['=A1+C1', 'col', 1, 1, '=A1+D1'],
            'borrar columna' => ['=SUMA(A1:D1)', 'col', 1, -1, '=SUMA(A1:C1)'],
            'los textos no se tocan' => ['="A1"&A1', 'fila', 0, 1, '="A1"&A2'],
            'funciones con números no son celdas' => ['=LOG10(A1)', 'fila', 0, 1, '=LOG10(A2)'],
        ];
    }

    #[DataProvider('desplazamientos')]
    public function test_references_shift_like_excel(string $formula, string $eje, int $desde, int $delta, string $esperada): void
    {
        $this->assertSame($esperada, FormulasHoja::desplazar($formula, $eje, $desde, $delta));
    }

    public function test_formulas_translate_to_excel_with_english_names_and_header_offset(): void
    {
        $this->assertSame('=SUM(A2:A6)+IF(B2>0,1,0)', FormulasHoja::aExcel('=SUMA(A1:A5)+SI(B1>0;1;0)'));
        $this->assertSame('=AVERAGE($B$2:B3)', FormulasHoja::aExcel('=promedio($B$1:B2)'));
        $this->assertSame('=CONCATENATE("x",TRUE)', FormulasHoja::aExcel('=CONCATENAR("x";VERDADERO)'));
        $this->assertSame('=#REF!+1', FormulasHoja::aExcel('=#¡REF!+1'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function noPermitidas(): array
    {
        return [
            'hipervínculo' => ['=HYPERLINK("http://x","clic")'],
            'servicio web' => ['=WEBSERVICE("http://x")'],
            'dde' => ["=cmd|'/c calc'!A1"],
            'nombre suelto' => ['=Hoja2'],
            'referencia a otra hoja' => ['=Hoja2!A1'],
            'no es fórmula' => ['texto'],
        ];
    }

    #[DataProvider('noPermitidas')]
    public function test_unknown_functions_and_foreign_syntax_are_not_exported_as_formulas(string $formula): void
    {
        $this->assertNull(FormulasHoja::aExcel($formula));
    }

    public function test_column_letters_round_trip(): void
    {
        foreach ([0 => 'A', 25 => 'Z', 26 => 'AA', 701 => 'ZZ', 702 => 'AAA'] as $indice => $letras) {
            $this->assertSame($letras, FormulasHoja::letrasColumna($indice));
            $this->assertSame($indice, FormulasHoja::indiceColumna($letras));
        }
    }
}
