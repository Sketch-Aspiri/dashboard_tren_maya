<?php

namespace Tests\Unit;

use App\Enums\EstatusAsistencia;
use App\Enums\TurnoAsistencia;
use App\Services\Asistencia\HojaAsistenciaCalculadora;
use PHPUnit\Framework\TestCase;

/**
 * Lógica portada del nodo "Calcular" de n8n: columna de hoy, fila por número o nombre, estatus de la hoja y
 * regla de la mañana (solo primer turno). Sin base de datos ni red.
 */
class HojaAsistenciaCalculadoraTest extends TestCase
{
    /** 2026-11-23 = serial 46349 en Excel/Sheets. */
    private const HOY = ['serial' => 46349, 'dia' => 23, 'mes' => 11, 'iso' => '2026-11-23'];

    /** @return list<list<mixed>> */
    private function hoja(mixed $encabezadoHoy = 46349): array
    {
        return [
            ['No.', 'Nombre', 'Puesto', 'Area', 46348, $encabezadoHoy],
            ['101', 'Ana Pérez López', 'x', 'x'],
            ['102.0', 'LIC. Luis Gómez', 'x', 'x'],
            [103, 'Rosa Díaz', 'x', 'x'],
        ];
    }

    private function fila(string $numero, string $nombre, ?EstatusAsistencia $estatus, ?TurnoAsistencia $turno): array
    {
        return ['estacion' => 'TULUM', 'nombre' => $nombre, 'no_empleado' => $numero, 'estatus' => $estatus, 'turno' => $turno];
    }

    private function calcular(array $filas, string $modo, ?array $hoja = null): array
    {
        return (new HojaAsistenciaCalculadora)->calcular($filas, $hoja ?? $this->hoja(), $modo, self::HOY, 'Noviembre');
    }

    public function test_escribe_el_estatus_en_la_columna_y_fila_de_la_persona(): void
    {
        $r = $this->calcular([
            $this->fila('101', 'Ana Pérez López', EstatusAsistencia::Presente, TurnoAsistencia::Primer),
            $this->fila('103', 'Rosa Díaz', EstatusAsistencia::LicenciaMedica, TurnoAsistencia::Segundo),
        ], 'tarde');

        $this->assertSame('F', $r['reporte']['columna']);
        $this->assertSame([
            ['range' => "'Noviembre'!F2", 'values' => [['PRESENTE']]],
            ['range' => "'Noviembre'!F4", 'values' => [['LICENCIA MEDICA']]],
        ], $r['writes']);
    }

    public function test_en_la_manana_solo_se_escribe_el_primer_turno(): void
    {
        $r = $this->calcular([
            $this->fila('101', 'Ana Pérez López', EstatusAsistencia::Presente, TurnoAsistencia::Primer),
            $this->fila('103', 'Rosa Díaz', EstatusAsistencia::Presente, TurnoAsistencia::Segundo),
        ], 'manana');

        $this->assertCount(1, $r['writes']);
        $this->assertSame("'Noviembre'!F2", $r['writes'][0]['range']);
        $this->assertSame(1, $r['reporte']['omitidos_por_turno']);
        $this->assertSame(1, $r['reporte']['esperados']);
    }

    public function test_sin_estatus_va_a_incompletos_y_no_se_escribe(): void
    {
        $r = $this->calcular([$this->fila('101', 'Ana Pérez López', null, TurnoAsistencia::Primer)], 'tarde');

        $this->assertSame([], $r['writes']);
        $this->assertSame('ESTATUS', $r['reporte']['incompletos'][0]['falta']);
    }

    public function test_si_el_numero_no_coincide_busca_por_nombre_sin_titulo_ni_acentos(): void
    {
        $r = $this->calcular([$this->fila('999', 'Luis Gomez', EstatusAsistencia::Vacaciones, TurnoAsistencia::Primer)], 'tarde');

        $this->assertSame("'Noviembre'!F3", $r['writes'][0]['range']);
        $this->assertSame('102', $r['reporte']['numero_distinto_pero_mismo_nombre'][0]['numero_hoja']);
    }

    public function test_persona_sin_fila_en_la_hoja_se_reporta(): void
    {
        $r = $this->calcular([$this->fila('555', 'Nadie Conocido', EstatusAsistencia::Presente, TurnoAsistencia::Primer)], 'tarde');

        $this->assertSame([], $r['writes']);
        $this->assertSame('555', $r['reporte']['sin_match_en_google'][0]['no_empleado']);
        $this->assertCount(3, $r['reporte']['en_google_pero_no_en_dashboard']);
    }

    public function test_entiende_el_encabezado_como_texto_de_fecha(): void
    {
        $r = $this->calcular(
            [$this->fila('101', 'Ana Pérez López', EstatusAsistencia::Descanso, TurnoAsistencia::Primer)],
            'tarde',
            $this->hoja('23 Noviembre'),
        );

        $this->assertSame('F', $r['reporte']['columna']);
        $this->assertSame('DESCANSO', $r['writes'][0]['values'][0][0]);
    }

    public function test_sin_columna_de_hoy_no_escribe_y_avisa(): void
    {
        $r = $this->calcular(
            [$this->fila('101', 'Ana Pérez López', EstatusAsistencia::Presente, TurnoAsistencia::Primer)],
            'tarde',
            $this->hoja(46400),
        );

        $this->assertSame([], $r['writes']);
        $this->assertNotEmpty($r['reporte']['errores']);
    }

    public function test_persona_repetida_solo_escribe_una_vez(): void
    {
        $fila = $this->fila('101', 'Ana Pérez López', EstatusAsistencia::Presente, TurnoAsistencia::Primer);
        $r = $this->calcular([$fila, $fila], 'tarde');

        $this->assertCount(1, $r['writes']);
        $this->assertNotEmpty($r['reporte']['avisos']);
    }

    public function test_todos_los_estatus_tienen_texto_de_hoja(): void
    {
        $esperado = [
            'presente' => 'PRESENTE', 'nuevo_ingreso' => 'PRESENTE', 'descanso' => 'DESCANSO', 'dia_no_laborable' => 'DIA NO LABORABLE',
            'licencia_medica' => 'LICENCIA MEDICA', 'comision' => 'COMISION', 'aviso_incidencia' => 'INCIDENCIA',
            'reporte_ausentismo' => 'AUSENTISMO', 'vacaciones' => 'VACACIONES', 'baja' => 'BAJA',
        ];

        foreach (EstatusAsistencia::cases() as $estatus) {
            $this->assertSame($esperado[$estatus->value], HojaAsistenciaCalculadora::textoDeHoja($estatus));
        }
    }

    public function test_columnas_mas_alla_de_la_z_usan_letras_dobles(): void
    {
        $encabezado = array_fill(0, 30, '');
        $encabezado[27] = 46349; // índice 27 = columna AB
        $r = $this->calcular(
            [$this->fila('101', 'Ana Pérez López', EstatusAsistencia::Presente, TurnoAsistencia::Primer)],
            'tarde',
            [$encabezado, ['101', 'Ana Pérez López']],
        );

        $this->assertSame('AB', $r['reporte']['columna']);
    }
}
