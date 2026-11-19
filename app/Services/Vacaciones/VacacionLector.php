<?php

namespace App\Services\Vacaciones;

use App\Support\ResolutorEstacion;
use App\Support\Texto;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Lee el Excel del rol de vacaciones: una hoja por estación, una fila por persona con sus cuatro
 * trimestres (Inicio, Término, Días solicitados). Los encabezados se repiten con espacios y números
 * ("Inicio ", "Inicio2"), así que los trimestres se leen por posición a partir de "Días otorgados".
 * La hoja consolidada ("CONSOLIDAD DGTZO") se omite: repite lo de las demás.
 */
final class VacacionLector
{
    public const MAX_FILAS = 5000;

    /** Nombre de la hoja del Excel (normalizado) => nombre de la estación en el catálogo. */
    private const ALIAS = [
        'limones chacchoben' => 'Limones',
        'nicolas bravo kohunlich' => 'Kohunlich',
        'chetumal aeropuerto' => 'Chetumal',
    ];

    public function __construct(private readonly ResolutorEstacion $resolutor) {}

    /**
     * @return array{personas: list<array<string, mixed>>, omitidas: list<string>, sin_estacion: array<string, int>, errores: list<string>, discrepancias: int, anio: int|null}
     */
    public function leer(string $ruta): array
    {
        $resultado = ['personas' => [], 'omitidas' => [], 'sin_estacion' => [], 'errores' => [], 'discrepancias' => 0, 'anio' => null];

        try {
            $lector = IOFactory::createReaderForFile($ruta);
            $lector->setReadDataOnly(true);
            $libro = $lector->load($ruta);
        } catch (Throwable) {
            $resultado['errores'][] = __('No se pudo leer el archivo: ¿es un Excel (.xlsx) válido?');

            return $resultado;
        }

        $vistos = [];

        foreach ($libro->getWorksheetIterator() as $hoja) {
            $this->leerHoja($hoja, $resultado, $vistos);

            if (count($resultado['personas']) > self::MAX_FILAS) {
                $resultado['errores'][] = __('El archivo tiene demasiadas filas.');
                $resultado['personas'] = [];

                return $resultado;
            }
        }

        $resultado['anio'] = $this->anioDominante($resultado['personas']);

        return $resultado;
    }

    /**
     * @param  array<string, mixed>  $resultado
     * @param  array<string, true>  $vistos
     */
    private function leerHoja(Worksheet $hoja, array &$resultado, array &$vistos): void
    {
        $titulo = $hoja->getTitle();
        $normal = Texto::normalizar($titulo);

        if (str_contains($normal, 'consolid') || $normal === 'ejemplo') {
            $resultado['omitidas'][] = $titulo;

            return;
        }

        $maxFila = min($hoja->getHighestDataRow(), self::MAX_FILAS + 50);
        $filas = $maxFila < 1 ? [] : $hoja->rangeToArray('A1:Z'.$maxFila, null, true, false);
        $cols = $this->encabezado($filas);

        if ($cols === null) {
            $resultado['omitidas'][] = $titulo;

            return;
        }

        $estacionId = $this->resolutor->resolver($titulo) ?? $this->resolutor->resolver(self::ALIAS[ResolutorEstacion::normalizar($titulo)] ?? null);

        foreach (array_slice($filas, $cols['fila'] + 1, null, true) as $numero => $fila) {
            $noEmpleado = $this->empleado($fila[$cols['no_empleado']] ?? null);
            $nombre = $this->texto($fila[$cols['nombre']] ?? null, 255);

            if ($noEmpleado === null && $nombre === null) {
                continue;
            }

            if ($noEmpleado === null || $nombre === null) {
                $resultado['errores'][] = __('Hoja :hoja, fila :fila: falta el número de empleado o el nombre.', ['hoja' => $titulo, 'fila' => $numero + 1]);

                continue;
            }

            if (isset($vistos[$noEmpleado])) {
                $resultado['errores'][] = __('Hoja :hoja: :nombre (:no) ya aparece en otra fila.', ['hoja' => $titulo, 'nombre' => $nombre, 'no' => $noEmpleado]);

                continue;
            }

            $vistos[$noEmpleado] = true;

            if ($estacionId === null) {
                $resultado['sin_estacion'][$titulo] = ($resultado['sin_estacion'][$titulo] ?? 0) + 1;
            }

            $tramos = [];

            for ($t = 1; $t <= 4; $t++) {
                $base = $cols['otorgados'] + 1 + ($t - 1) * 3;
                $inicio = $this->fecha($fila[$base] ?? null);
                $fin = $this->fecha($fila[$base + 1] ?? null);

                if ($inicio === null || $fin === null || $fin < $inicio) {
                    $inicio = $fin = null;
                }

                $dias = $inicio === null ? 0 : VacacionCalculo::diasHabiles($inicio, $fin);
                $enArchivo = (int) round((float) ($fila[$base + 2] ?? 0));

                if ($inicio !== null && $enArchivo !== $dias) {
                    $resultado['discrepancias']++;
                    $dias = $enArchivo > 0 ? $enArchivo : $dias;
                }

                $tramos[$t] = ['inicio' => $inicio?->toDateString(), 'fin' => $fin?->toDateString(), 'dias' => $dias];
            }

            $resultado['personas'][] = [
                'estacion_id' => $estacionId,
                'no_empleado' => $noEmpleado,
                'nombre' => $nombre,
                'puesto' => isset($cols['puesto']) ? $this->texto($fila[$cols['puesto']] ?? null, 255) : null,
                'dias_otorgados' => (int) round((float) ($fila[$cols['otorgados']] ?? 0)),
                'tramos' => $tramos,
            ];
        }
    }

    /**
     * @param  list<list<mixed>>  $filas
     * @return array{fila: int, no_empleado: int, nombre: int, otorgados: int, puesto?: int}|null
     */
    private function encabezado(array $filas): ?array
    {
        foreach (array_slice($filas, 0, 8, true) as $indice => $fila) {
            $cols = ['fila' => $indice];

            foreach ($fila as $posicion => $celda) {
                $texto = trim((string) preg_replace('/[^a-z0-9 ]+/', ' ', Texto::normalizar($celda)));
                $texto = (string) preg_replace('/\s+/', ' ', $texto);

                match (true) {
                    $texto === 'no empleado' => $cols['no_empleado'] ??= $posicion,
                    $texto === 'nombre completo' => $cols['nombre'] ??= $posicion,
                    str_starts_with($texto, 'dias otorgados') => $cols['otorgados'] ??= $posicion,
                    str_starts_with($texto, 'denominacion') => $cols['puesto'] ??= $posicion,
                    default => null,
                };
            }

            if (isset($cols['no_empleado'], $cols['nombre'], $cols['otorgados'])) {
                return $cols;
            }
        }

        return null;
    }

    private function empleado(mixed $valor): ?string
    {
        if (is_float($valor) && floor($valor) === $valor) {
            $valor = (int) $valor;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : mb_substr($texto, 0, 30);
    }

    private function fecha(mixed $valor): ?CarbonImmutable
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        try {
            if (is_numeric($valor)) {
                $numero = (float) $valor;

                return $numero > 36526 && $numero < 73415 ? CarbonImmutable::instance(Date::excelToDateTimeObject($numero))->startOfDay() : null;
            }

            $texto = trim((string) $valor);

            if (preg_match('#^\d{1,2}/\d{1,2}/\d{4}$#', $texto) === 1) {
                return CarbonImmutable::createFromFormat('!d/m/Y', $texto) ?: null;
            }

            return preg_match('/^\d{4}-\d{2}-\d{2}/', $texto) === 1 ? CarbonImmutable::parse(substr($texto, 0, 10))->startOfDay() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function texto(mixed $valor, int $max): ?string
    {
        if ($valor === null) {
            return null;
        }

        $texto = trim((string) preg_replace('/\s*\R\s*/u', ' ', (string) $valor));

        return $texto === '' ? null : mb_substr($texto, 0, $max);
    }

    /**
     * @param  list<array<string, mixed>>  $personas
     */
    private function anioDominante(array $personas): ?int
    {
        $conteo = [];

        foreach ($personas as $p) {
            foreach ($p['tramos'] as $t) {
                if ($t['inicio'] !== null) {
                    $anio = (int) substr($t['inicio'], 0, 4);
                    $conteo[$anio] = ($conteo[$anio] ?? 0) + 1;
                }
            }
        }

        if ($conteo === []) {
            return null;
        }

        arsort($conteo);

        return (int) array_key_first($conteo);
    }
}
