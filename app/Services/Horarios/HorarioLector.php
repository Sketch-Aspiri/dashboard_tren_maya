<?php

namespace App\Services\Horarios;

use App\Models\Estacion;
use App\Support\ResolutorEstacion;
use App\Support\Texto;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Lee el Excel oficial de horarios (una hoja por estación, una fila por persona
 * y día) sin escribir nada. Las columnas se buscan por su encabezado, no por su
 * posición; las hojas sin encabezados reconocibles (EJEMPLO, catálogos) se
 * omiten. La estación sale del prefijo de la hoja ("23_Puerto Morelos." → 23) y,
 * si la hoja no lo tiene, de la celda "Estación".
 */
final class HorarioLector
{
    public const MAX_FILAS = 20000;

    private const OBLIGATORIAS = ['no_empleado', 'nombre', 'fecha', 'jornada'];

    /** @var array<string, int>|null codigo => estacion_id */
    private ?array $porCodigo = null;

    public function __construct(private readonly ResolutorEstacion $resolutor) {}

    /**
     * @return array{
     *     filas: list<array<string, mixed>>,
     *     hojas: list<array{nombre: string, filas: int, estacion_id: int|null}>,
     *     omitidas: list<string>,
     *     sin_estacion: array<string, int>,
     *     errores: list<string>,
     *     recuperadas: int,
     *     periodo: array{anio: int, mes: int}|null
     * }
     */
    public function leer(string $ruta): array
    {
        $resultado = ['filas' => [], 'hojas' => [], 'omitidas' => [], 'sin_estacion' => [], 'errores' => [], 'recuperadas' => 0, 'periodo' => null];

        try {
            $lector = IOFactory::createReaderForFile($ruta);
            $lector->setReadDataOnly(true);
            $libro = $lector->load($ruta);
        } catch (Throwable) {
            $resultado['errores'][] = __('No se pudo leer el archivo: ¿es un Excel (.xlsx) válido?');

            return $resultado;
        }

        foreach ($libro->getWorksheetIterator() as $hoja) {
            $this->leerHoja($hoja, $resultado);

            if (count($resultado['filas']) > self::MAX_FILAS) {
                $resultado['errores'][] = __('El archivo tiene más de :n filas de horario.', ['n' => self::MAX_FILAS]);
                $resultado['filas'] = [];

                return $resultado;
            }
        }

        $resultado['periodo'] = $this->periodoDominante($resultado['filas']);

        return $resultado;
    }

    /**
     * @param  array<string, mixed>  $resultado
     */
    private function leerHoja(Worksheet $hoja, array &$resultado): void
    {
        $titulo = $hoja->getTitle();

        if (Texto::normalizar($titulo) === 'ejemplo') {
            $resultado['omitidas'][] = $titulo;

            return;
        }

        $maxFila = min($hoja->getHighestDataRow(), self::MAX_FILAS + 50);
        $filas = $maxFila < 1 ? [] : $hoja->rangeToArray('A1:J'.$maxFila, null, true, false);
        [$indice, $columnas] = $this->encabezado($filas);

        if ($columnas === null) {
            $resultado['omitidas'][] = $titulo;

            return;
        }

        $estacionHoja = $this->estacionDeHoja($titulo);
        $leidas = 0;
        $inicioHoja = count($resultado['filas']);

        foreach (array_slice($filas, $indice + 1, null, true) as $numero => $fila) {
            $noEmpleado = $this->empleado($fila[$columnas['no_empleado']] ?? null);
            $fecha = $this->fecha($fila[$columnas['fecha']] ?? null);

            $nombre = $this->texto($fila[$columnas['nombre']] ?? null, 255);

            if ($noEmpleado === null && $fecha === null) {
                continue;
            }

            if ($fecha === null || ($noEmpleado === null && $nombre === null)) {
                $resultado['errores'][] = __('Hoja :hoja, fila :fila: falta el número de empleado o la fecha es inválida.', ['hoja' => $titulo, 'fila' => $numero + 1]);

                continue;
            }

            $textoEstacion = isset($columnas['estacion']) ? trim((string) ($fila[$columnas['estacion']] ?? '')) : '';
            $estacionId = $estacionHoja ?? $this->resolverTexto($textoEstacion);

            if ($estacionId === null) {
                $clave = $titulo.($textoEstacion !== '' ? ' ('.$textoEstacion.')' : '');
                $resultado['sin_estacion'][$clave] = ($resultado['sin_estacion'][$clave] ?? 0) + 1;
            }

            $resultado['filas'][] = [
                'estacion_id' => $estacionId,
                'no_empleado' => $noEmpleado,
                'nombre' => $nombre ?? '',
                'puesto' => isset($columnas['puesto']) ? $this->texto($fila[$columnas['puesto']] ?? null, 255) : null,
                'fecha' => $fecha->toDateString(),
                'jornada' => $this->texto($fila[$columnas['jornada']] ?? null, 255) ?? '',
                'comida' => isset($columnas['comida']) ? $this->texto($fila[$columnas['comida']] ?? null, 100) : null,
                'semana' => isset($columnas['semana']) ? $this->texto($fila[$columnas['semana']] ?? null, 30) : null,
            ];
            $leidas++;
        }

        $this->recuperarEmpleados($resultado, $inicioHoja, $titulo);

        $resultado['hojas'][] = ['nombre' => $titulo, 'filas' => $leidas, 'estacion_id' => $estacionHoja];
    }

    /**
     * Una fila sin No. Empleado pero con el nombre de alguien que sí lo trae en la
     * misma hoja toma ese número (error de captura frecuente en el oficial). Si no
     * hay de dónde recuperarlo, se reporta y se omite la fila.
     *
     * @param  array<string, mixed>  $resultado
     */
    private function recuperarEmpleados(array &$resultado, int $desde, string $titulo): void
    {
        $conocidos = [];

        for ($i = $desde, $n = count($resultado['filas']); $i < $n; $i++) {
            $fila = $resultado['filas'][$i];

            if ($fila['no_empleado'] !== null) {
                $conocidos[Texto::normalizar($fila['nombre'])] ??= $fila['no_empleado'];
            }
        }

        $conservadas = array_slice($resultado['filas'], 0, $desde);

        for ($i = $desde, $n = count($resultado['filas']); $i < $n; $i++) {
            $fila = $resultado['filas'][$i];

            if ($fila['no_empleado'] === null) {
                $recuperado = $conocidos[Texto::normalizar($fila['nombre'])] ?? null;

                if ($recuperado === null) {
                    $resultado['errores'][] = __('Hoja :hoja: :nombre del :fecha no tiene número de empleado.', ['hoja' => $titulo, 'nombre' => $fila['nombre'], 'fecha' => $fila['fecha']]);

                    continue;
                }

                $fila['no_empleado'] = $recuperado;
                $resultado['recuperadas']++;
            }

            $conservadas[] = $fila;
        }

        $resultado['filas'] = $conservadas;
    }

    /**
     * @param  list<list<mixed>>  $filas
     * @return array{0: int, 1: array<string, int>|null}
     */
    private function encabezado(array $filas): array
    {
        foreach (array_slice($filas, 0, 5, true) as $indice => $fila) {
            $columnas = [];

            foreach ($fila as $posicion => $celda) {
                $clave = $this->claveEncabezado((string) $celda);

                if ($clave !== null && ! isset($columnas[$clave])) {
                    $columnas[$clave] = $posicion;
                }
            }

            if (array_diff(self::OBLIGATORIAS, array_keys($columnas)) === []) {
                return [$indice, $columnas];
            }
        }

        return [0, null];
    }

    private function claveEncabezado(string $celda): ?string
    {
        $texto = trim((string) preg_replace('/[^a-z0-9 ]+/', ' ', Texto::normalizar($celda)));
        $texto = (string) preg_replace('/\s+/', ' ', $texto);

        return match (true) {
            $texto === 'no empleado', $texto === 'numero de empleado' => 'no_empleado',
            $texto === 'nombre completo', $texto === 'nombre' => 'nombre',
            $texto === 'denominacion del puesto', $texto === 'puesto' => 'puesto',
            $texto === 'estacion' => 'estacion',
            $texto === 'fecha' => 'fecha',
            $texto === 'jornada laboral', $texto === 'jornada' => 'jornada',
            str_starts_with($texto, 'horario de comida') => 'comida',
            $texto === 'semana' => 'semana',
            default => null,
        };
    }

    private function estacionDeHoja(string $titulo): ?int
    {
        if (preg_match('/^\s*(\d{1,2})\s*_/', $titulo, $m) !== 1) {
            return null;
        }

        return $this->porCodigo()[str_pad($m[1], 2, '0', STR_PAD_LEFT)] ?? null;
    }

    /**
     * @return array<string, int>
     */
    private function porCodigo(): array
    {
        return $this->porCodigo ??= Estacion::query()->whereNotNull('codigo')->pluck('id', 'codigo')->all();
    }

    private function resolverTexto(string $texto): ?int
    {
        $limpio = trim((string) preg_replace('/^\s*\d{1,2}\s*_\s*/', '', $texto), " .\t");

        return $limpio === '' ? null : $this->resolutor->resolver($limpio);
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

                // Número de serie de Excel entre 2000 y 2100.
                return $numero > 36526 && $numero < 73415 ? CarbonImmutable::instance(Date::excelToDateTimeObject($numero))->startOfDay() : null;
            }

            $texto = trim((string) $valor);

            if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $texto, $m) === 1) {
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

        $texto = trim((string) preg_replace('/\s*\R\s*/u', ' / ', (string) $valor));

        return $texto === '' ? null : mb_substr($texto, 0, $max);
    }

    /**
     * @param  list<array<string, mixed>>  $filas
     * @return array{anio: int, mes: int}|null
     */
    private function periodoDominante(array $filas): ?array
    {
        $conteo = [];

        foreach ($filas as $fila) {
            $clave = substr((string) $fila['fecha'], 0, 7);
            $conteo[$clave] = ($conteo[$clave] ?? 0) + 1;
        }

        if ($conteo === []) {
            return null;
        }

        arsort($conteo);
        [$anio, $mes] = explode('-', (string) array_key_first($conteo));

        return ['anio' => (int) $anio, 'mes' => (int) $mes];
    }
}
