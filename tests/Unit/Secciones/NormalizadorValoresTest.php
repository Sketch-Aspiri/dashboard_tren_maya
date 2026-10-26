<?php

namespace Tests\Unit\Secciones;

use App\Enums\TipoColumna;
use App\Services\Secciones\NormalizadorValores;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NormalizadorValoresTest extends TestCase
{
    private NormalizadorValores $normalizador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizador = new NormalizadorValores;
    }

    /**
     * @return array<string, array{mixed, int|float|null}>
     */
    public static function numeros(): array
    {
        return [
            'entero' => ['42', 42],
            'miles mexicanos' => ['1,234.50', 1234.5],
            'moneda' => ['$ 1,200', 1200],
            'coma decimal' => ['12,5', 12.5],
            'porcentaje' => ['15%', 0.15],
            'texto' => ['abc', null],
            'nativo' => [7.25, 7.25],
        ];
    }

    #[DataProvider('numeros')]
    public function test_converts_numbers(mixed $entrada, int|float|null $esperado): void
    {
        $this->assertSame($esperado, $this->normalizador->numero($entrada));
    }

    /**
     * @return array<string, array{mixed, ?string}>
     */
    public static function fechas(): array
    {
        return [
            'iso' => ['2026-09-15', '2026-09-15'],
            'dia/mes/año' => ['15/09/2026', '2026-09-15'],
            'año corto' => ['15-09-26', '2026-09-15'],
            'en español' => ['15 de septiembre de 2026', '2026-09-15'],
            'mes abreviado' => ['3-ago-2026', '2026-08-03'],
            'serial de Excel' => [46280, '2026-09-15'],
            'fecha imposible' => ['31/02/2026', null],
            'texto' => ['pendiente', null],
        ];
    }

    #[DataProvider('fechas')]
    public function test_converts_dates(mixed $entrada, ?string $esperado): void
    {
        $this->assertSame($esperado, $this->normalizador->fecha($entrada));
    }

    public function test_converts_booleans(): void
    {
        $this->assertTrue($this->normalizador->booleano('Sí'));
        $this->assertTrue($this->normalizador->booleano('x'));
        $this->assertFalse($this->normalizador->booleano('NO'));
        $this->assertNull($this->normalizador->booleano('tal vez'));
    }

    public function test_keeps_original_text_when_conversion_fails(): void
    {
        $this->assertSame('sin dato', $this->normalizador->normalizar('sin dato', TipoColumna::Numero));
        $this->assertNull($this->normalizador->normalizar('   ', TipoColumna::Texto));
    }

    public function test_infers_column_types(): void
    {
        $this->assertSame(TipoColumna::Numero, $this->normalizador->inferirTipo(['1', '2', '3,000']));
        $this->assertSame(TipoColumna::Fecha, $this->normalizador->inferirTipo(['2026-01-01', '15/09/2026', null]));
        $this->assertSame(TipoColumna::Booleano, $this->normalizador->inferirTipo(['Sí', 'No', 'si']));
        $this->assertSame(TipoColumna::Texto, $this->normalizador->inferirTipo(['Chetumal', '12', 'Tulum']));
        $this->assertSame(TipoColumna::Texto, $this->normalizador->inferirTipo([]));
    }

    public function test_formats_values_for_display(): void
    {
        $this->assertSame('15/09/2026', $this->normalizador->paraMostrar('2026-09-15', TipoColumna::Fecha));
        $this->assertSame('1,234', $this->normalizador->paraMostrar(1234, TipoColumna::Numero));
        $this->assertSame('Sí', $this->normalizador->paraMostrar(true, TipoColumna::Booleano));
        $this->assertSame('', $this->normalizador->paraMostrar(null, TipoColumna::Texto));
    }

    /**
     * @return array<string, array{mixed, ?string}>
     */
    public static function telefonos(): array
    {
        return [
            'ya formateado' => ['983 168 7870', '983 168 7870'],
            'solo dígitos' => ['9831687870', '983 168 7870'],
            'guiones' => ['983-168-7870', '983 168 7870'],
            'puntos' => ['983.168.7870', '983 168 7870'],
            'parentesis' => ['(983) 168 78 70', '983 168 7870'],
            'lada +52' => ['+52 983 168 7870', '983 168 7870'],
            'lada 52 sin signo' => ['529831687870', '983 168 7870'],
            'celular +52 1' => ['+52 1 983 168 7870', '983 168 7870'],
            'prefijo 044' => ['044 983 168 7870', '983 168 7870'],
            'prefijo 01' => ['01 983 168 7870', '983 168 7870'],
            'cifra de excel (int)' => [9831687870, '983 168 7870'],
            'cifra de excel (float)' => [9831687870.0, '983 168 7870'],
            'con extensión' => ['983 168 7870 ext. 123', '983 168 7870 ext. 123'],
            'extensión abreviada' => ['9831687870 x45', '983 168 7870 ext. 45'],
            'demasiados dígitos' => ['9883-9383-8383', null],
            'miles mal interpretados' => ['9,892,9828,000', null],
            'incompleto' => ['168 7870', null],
            'con letras' => ['llamar a Juan 9831687870', null],
            'vacío' => ['', null],
        ];
    }

    #[DataProvider('telefonos')]
    public function test_phone_numbers_are_formatted_as_ten_digits_in_groups(mixed $valor, ?string $esperado): void
    {
        $this->assertSame($esperado, $this->normalizador->telefono($valor));
    }

    public function test_phone_column_keeps_the_original_text_when_it_is_not_a_phone(): void
    {
        $this->assertSame('983 168 7870', $this->normalizador->normalizar('983-168-7870', TipoColumna::Telefono));
        $this->assertSame('9883-9383-8383', $this->normalizador->normalizar('9883-9383-8383', TipoColumna::Telefono));
        $this->assertNull($this->normalizador->normalizar('  ', TipoColumna::Telefono));
    }

    public function test_phone_type_is_inferred_from_separators_or_the_header(): void
    {
        $this->assertSame(TipoColumna::Telefono, $this->normalizador->inferirTipo(['983-168-7870', '(998) 123 45 67', '998.123.4568']));
        $this->assertSame(TipoColumna::Telefono, $this->normalizador->inferirTipo([9831687870, 9981234567], 'Celular'));
        // Sin pistas, cifras de 10 dígitos siguen siendo números (podrían ser folios).
        $this->assertSame(TipoColumna::Numero, $this->normalizador->inferirTipo([9831687870, 9981234567], 'Folio'));
    }
}
