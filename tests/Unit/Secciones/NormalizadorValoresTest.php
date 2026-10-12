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
}
