<?php

namespace App\Services\Afluencia;

use App\Models\AfluenciaDiaria;
use App\Support\ResolutorEstacion;
use App\Support\Texto;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Carga el "Reporte diario de ascensos y boletos vendidos" (una hoja por mes, bloques DÍA / ABORDAN / BOLETOS VENDIDOS
 * por estación). Los bloques se ubican por sus encabezados y el nombre de la estación de la fila de arriba, no por
 * celdas fijas, porque febrero y abril desplazan filas. Celda vacía = sin captura; los totales se ignoran.
 */
final class AfluenciaImportador
{
    private const MESES = ['enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6, 'julio' => 7, 'agosto' => 8, 'septiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12];

    private const ALIAS = ['nicolas bravo' => 'Kohunlich', 'limones chaachoben' => 'Limones'];

    public function __construct(private readonly ResolutorEstacion $resolutor) {}

    /**
     * @return array{dias: list<array{estacion_id: int, fecha: string, abordan: int|null, boletos: int|null}>, errores: list<string>, sin_estacion: list<string>}
     */
    public function leer(string $ruta, int $anio): array
    {
        $libro = IOFactory::load($ruta);
        $salida = ['dias' => [], 'errores' => [], 'sin_estacion' => []];

        foreach ($libro->getWorksheetIterator() as $hoja) {
            $mes = self::MESES[Texto::normalizar($hoja->getTitle())] ?? null;

            if ($mes === null) {
                $salida['errores'][] = __('Hoja ":h": no es un mes; se omite.', ['h' => trim($hoja->getTitle())]);

                continue;
            }

            $this->hoja($hoja, $anio, $mes, $salida);
        }

        return $salida;
    }

    /**
     * Guarda lo leído; repetirlo no duplica (actualiza por estación y fecha).
     *
     * @param  array{dias: list<array{estacion_id: int, fecha: string, abordan: int|null, boletos: int|null}>}  $leido
     * @return array{creados: int, actualizados: int}
     */
    public function aplicar(array $leido): array
    {
        $r = ['creados' => 0, 'actualizados' => 0];

        DB::transaction(function () use ($leido, &$r): void {
            foreach ($leido['dias'] as $d) {
                $modelo = AfluenciaDiaria::query()->updateOrCreate(
                    ['estacion_id' => $d['estacion_id'], 'fecha' => $d['fecha']],
                    ['abordan' => $d['abordan'], 'boletos' => $d['boletos']],
                );

                if ($modelo->wasRecentlyCreated) {
                    $r['creados']++;
                } elseif ($modelo->wasChanged()) {
                    $r['actualizados']++;
                }
            }
        });

        return $r;
    }

    /** @param  array{dias: list<array<string, mixed>>, errores: list<string>, sin_estacion: list<string>}  $salida */
    private function hoja(Worksheet $hoja, int $anio, int $mes, array &$salida): void
    {
        $filas = $hoja->toArray(null, true, false, false);
        $diasMes = CarbonImmutable::create($anio, $mes, 1)->daysInMonth;

        foreach ($filas as $i => $fila) {
            foreach ($fila as $c => $valor) {
                if (Texto::normalizar($valor) !== 'dia'
                    || Texto::normalizar($fila[$c + 1] ?? null) !== 'abordan'
                    || ! str_starts_with(Texto::normalizar($fila[$c + 2] ?? null), 'boletos')) {
                    continue;
                }

                $nombre = $filas[$i - 1][$c] ?? null;
                $estacionId = $this->resolutor->resolver(self::ALIAS[Texto::normalizar($nombre)] ?? $nombre);

                if ($estacionId === null) {
                    $salida['sin_estacion'][] = trim((string) $nombre).' ('.$hoja->getTitle().')';

                    continue;
                }

                for ($j = $i + 1; isset($filas[$j]) && is_numeric($filas[$j][$c] ?? null); $j++) {
                    $dia = (int) $filas[$j][$c];
                    $abordan = $this->numero($filas[$j][$c + 1] ?? null);
                    $boletos = $this->numero($filas[$j][$c + 2] ?? null);

                    if ($abordan === null && $boletos === null) {
                        continue;
                    }

                    if ($dia < 1 || $dia > $diasMes) {
                        $salida['errores'][] = __(':h: día :d fuera del mes; se omite.', ['h' => $hoja->getTitle(), 'd' => $dia]);
                    } else {
                        $salida['dias'][] = [
                            'estacion_id' => $estacionId,
                            'fecha' => CarbonImmutable::create($anio, $mes, $dia)->toDateString(),
                            'abordan' => $abordan,
                            'boletos' => $boletos,
                        ];
                    }
                }
            }
        }
    }

    private function numero(mixed $valor): ?int
    {
        return is_numeric($valor) && $valor >= 0 ? (int) round((float) $valor) : null;
    }
}
