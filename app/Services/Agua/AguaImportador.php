<?php

namespace App\Services\Agua;

use App\Models\AguaCompra;
use App\Models\AguaRegistro;
use App\Support\ResolutorEstacion;
use App\Support\Texto;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Carga el control de agua de la Zona (Excel "PROGRAMA DE ADQUISICIÓN AGUA"): una hoja por mes con el programa de
 * cada estación y el bloque de pagos. Se lee por etiquetas de encabezado, no por posición. Los pagos entran como
 * compras importadas (sin comprobante); los meses anteriores al último quedan aprobados y el último en borrador.
 */
final class AguaImportador
{
    private const MESES = ['enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6, 'julio' => 7, 'agosto' => 8, 'septiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12];

    private const ALIAS = [
        'limones chaachoben' => 'Limones',
        'limones chacchoben' => 'Limones',
        'nicolas bravo' => 'Kohunlich',
        'edificio zonal' => 'Edificio Zonal Este',
    ];

    public function __construct(private readonly ResolutorEstacion $resolutor) {}

    /**
     * @return array{registros: list<array<string, mixed>>, errores: list<string>, sin_estacion: list<string>}
     */
    public function leer(string $ruta, int $anio): array
    {
        $libro = IOFactory::load($ruta);
        $salida = ['registros' => [], 'errores' => [], 'sin_estacion' => []];
        $hojas = [];

        foreach ($libro->getWorksheetIterator() as $hoja) {
            $mes = self::MESES[mb_strtolower(trim($hoja->getTitle()))] ?? null;

            if ($mes === null) {
                $salida['errores'][] = __('Hoja ":h": no es un mes; se omite.', ['h' => trim($hoja->getTitle())]);

                continue;
            }

            $hojas[$mes] = $hoja;
        }

        $ultimo = $hojas === [] ? 0 : max(array_keys($hojas));
        ksort($hojas);

        foreach ($hojas as $mes => $hoja) {
            $filas = $hoja->toArray(null, true, false, false);
            $programa = $this->programa($filas, $salida);
            $pagos = $this->pagos($filas, $salida);

            foreach ($programa as $estacionId => $p) {
                $salida['registros'][] = $p + [
                    'estacion_id' => $estacionId, 'anio' => $anio, 'mes' => $mes,
                    'estado' => $mes < $ultimo ? AguaRegistro::APROBADA : AguaRegistro::BORRADOR,
                    'pagos' => $pagos[$estacionId]['pagos'] ?? [],
                    'dia_entrega' => $pagos[$estacionId]['dia_entrega'] ?? null,
                ];
            }
        }

        return $salida;
    }

    /**
     * Guarda lo leído sin pisar registros que ya existen.
     *
     * @param  array{registros: list<array<string, mixed>>}  $leido
     * @return array{creados: int, omitidos: int, compras: int}
     */
    public function aplicar(array $leido): array
    {
        $resultado = ['creados' => 0, 'omitidos' => 0, 'compras' => 0];

        DB::transaction(function () use ($leido, &$resultado) {
            foreach ($leido['registros'] as $r) {
                $existente = AguaRegistro::query()->where(['estacion_id' => $r['estacion_id'], 'anio' => $r['anio'], 'mes' => $r['mes']])->first();

                if ($existente !== null) {
                    $resultado['omitidos']++;

                    continue;
                }

                $registro = AguaRegistro::query()->create(collect($r)->only(['estacion_id', 'anio', 'mes', 'estado', 'frecuencia', 'consumo_mensual', 'costo_unitario', 'garrafones_propios', 'garrafones_comodato', 'referencia', 'dia_entrega'])->all());
                $resultado['creados']++;

                foreach ($r['pagos'] as $i => $monto) {
                    $costo = (float) $r['costo_unitario'];
                    $registro->compras()->create([
                        'fecha' => null,
                        'garrafones' => $costo > 0 ? (int) round($monto / $costo) : 0,
                        'costo_unitario' => $costo,
                        'monto' => $monto,
                        'nota' => 'Pago '.($i + 1).' (importado del Excel)',
                        'origen' => AguaCompra::IMPORTACION,
                    ]);
                    $resultado['compras']++;
                }
            }
        });

        return $resultado;
    }

    /**
     * @param  list<list<mixed>>  $filas
     * @param  array<string, mixed>  $salida
     * @return array<int, array<string, mixed>> estacion_id => programa
     */
    private function programa(array $filas, array &$salida): array
    {
        $cabecera = $this->columnas($filas, 'frecuencia de compra', ['consumo' => 'consumo mensual neto', 'costo' => 'costo unitario', 'propios' => 'propios', 'comodato' => 'comodato', 'referencia' => 'referencia']);
        $programa = [];

        foreach ($filas as $fila) {
            $frecuencia = mb_strtolower(trim((string) ($fila[1] ?? '')));

            if (! in_array($frecuencia, ['quincenal', 'semanal'], true) || $cabecera === null) {
                continue;
            }

            $id = $this->estacion($fila[0] ?? null, $salida);

            if ($id === null) {
                continue;
            }

            $programa[$id] = [
                'frecuencia' => $frecuencia,
                'consumo_mensual' => (int) ($fila[$cabecera['consumo']] ?? 0),
                'costo_unitario' => (float) ($fila[$cabecera['costo']] ?? 0),
                'garrafones_propios' => (int) ($fila[$cabecera['propios']] ?? 0),
                'garrafones_comodato' => (int) ($fila[$cabecera['comodato']] ?? 0),
                'referencia' => isset($cabecera['referencia']) ? ($this->texto($fila[$cabecera['referencia']] ?? null)) : null,
            ];
        }

        return $programa;
    }

    /**
     * @param  list<list<mixed>>  $filas
     * @param  array<string, mixed>  $salida
     * @return array<int, array{pagos: list<float>, dia_entrega: string|null}>
     */
    private function pagos(array $filas, array &$salida): array
    {
        $cabecera = $this->columnas($filas, 'primer pago', ['p1' => 'primer pago', 'p2' => 'segundo pago', 'p3' => 'tercer pago', 'p4' => 'cuarto pago', 'dia' => 'dia de entrega']);
        $inicio = $this->filaDe($filas, 'primer pago');
        $pagos = [];

        if ($cabecera === null || $inicio === null) {
            return $pagos;
        }

        foreach (array_slice($filas, $inicio + 1) as $fila) {
            if (trim((string) ($fila[0] ?? '')) === '') {
                break;
            }

            $id = $this->estacion($fila[0], $salida);

            if ($id === null) {
                continue;
            }

            $montos = [];

            foreach (['p1', 'p2', 'p3', 'p4'] as $clave) {
                $valor = $fila[$cabecera[$clave]] ?? null;

                if (is_numeric($valor) && (float) $valor > 0) {
                    $montos[] = round((float) $valor, 2);
                }
            }

            $pagos[$id] = ['pagos' => $montos, 'dia_entrega' => isset($cabecera['dia']) ? $this->texto($fila[$cabecera['dia']] ?? null) : null];
        }

        return $pagos;
    }

    /** @param  array<string, mixed>  $salida */
    private function estacion(mixed $texto, array &$salida): ?int
    {
        $id = $this->resolutor->resolver($texto) ?? $this->resolutor->resolver(self::ALIAS[ResolutorEstacion::normalizar($texto) ?? ''] ?? null);

        if ($id === null && trim((string) $texto) !== '') {
            $salida['sin_estacion'][trim((string) $texto)] = trim((string) $texto);
            $salida['sin_estacion'] = array_unique($salida['sin_estacion']);
        }

        return $id;
    }

    private function texto(mixed $valor): ?string
    {
        $texto = trim((string) $valor);

        return $texto === '' ? null : mb_substr($texto, 0, 60);
    }

    /**
     * Índices de columna de los encabezados, a partir de la fila que contiene $ancla.
     *
     * @param  list<list<mixed>>  $filas
     * @param  array<string, string>  $buscar  clave => texto normalizado que debe contener el encabezado
     * @return array<string, int>|null
     */
    private function columnas(array $filas, string $ancla, array $buscar): ?array
    {
        $i = $this->filaDe($filas, $ancla);

        if ($i === null) {
            return null;
        }

        $mapa = [];

        foreach ($filas[$i] as $col => $texto) {
            $norm = Texto::normalizar($texto);

            foreach ($buscar as $clave => $patron) {
                if ($norm !== '' && str_contains($norm, $patron) && ! isset($mapa[$clave])) {
                    $mapa[$clave] = $col;
                }
            }
        }

        $requeridas = array_diff(array_keys($buscar), ['referencia', 'dia']);

        return count(array_intersect_key(array_flip($requeridas), $mapa)) === count($requeridas) ? $mapa : null;
    }

    /** @param  list<list<mixed>>  $filas */
    private function filaDe(array $filas, string $ancla): ?int
    {
        foreach ($filas as $i => $fila) {
            foreach ($fila as $celda) {
                if (str_contains(Texto::normalizar($celda), $ancla)) {
                    return $i;
                }
            }
        }

        return null;
    }
}
