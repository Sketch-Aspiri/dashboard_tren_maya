<?php

namespace App\Services\Ptar;

use App\Models\Estacion;
use App\Models\PtarHallazgo;
use App\Models\PtarPlanta;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Carga los hallazgos desde el oficio mensual en Word (el que la Zona armaba a mano): después del encabezado
 * "INFORMACIÓN" cada planta lleva su nombre, el rótulo "Problemática(s)." y las líneas de problemáticas, y el rótulo
 * "Propuesta(s)." o "Acciones." con las propuestas. Termina en "CONSIDERACIÓN". Cada línea es un hallazgo abierto;
 * si ya existe uno igual en esa planta no se duplica.
 */
final class PtarHallazgosImportador
{
    private const MESES = ['ene' => 1, 'feb' => 2, 'mar' => 3, 'abr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'ago' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dic' => 12];

    private const ALIAS = [
        'limones chacchoben' => 'Limones',
        'chetumal aeropuerto' => 'Chetumal',
        'nicolas bravo kohunlich' => 'Kohunlich',
    ];

    /**
     * @return array{fecha: string|null, hallazgos: list<array{estacion: string, tipo: string, descripcion: string}>, avisos: list<string>}
     */
    public function leer(string $ruta): array
    {
        $parrafos = $this->parrafos($ruta);
        $salida = ['fecha' => null, 'hallazgos' => [], 'avisos' => []];
        $dentro = false;
        $estacion = null;
        $tipo = null;
        $nombres = collect(config('ptar.plantas'))->keys()->mapWithKeys(fn (string $n) => [$this->normalizar($n) => $n])->all() + self::ALIAS;

        foreach ($parrafos as $texto) {
            $clave = $this->normalizar($texto);

            if ($salida['fecha'] === null && preg_match('/^\((\d{1,2})\s+([[:alpha:]]{3})[[:alpha:]]*\.?\s+(\d{4})\)$/u', trim($texto), $m) === 1 && isset(self::MESES[Str::lower(Str::ascii($m[2]))])) {
                $salida['fecha'] = sprintf('%04d-%02d-%02d', $m[3], self::MESES[Str::lower(Str::ascii($m[2]))], $m[1]);
            }

            if (str_starts_with($clave, 'informacion') && strlen($clave) < 14) {
                $dentro = true;

                continue;
            }

            if (! $dentro || $clave === '') {
                continue;
            }

            if (str_starts_with($clave, 'consideracion') && strlen($clave) < 16) {
                break;
            }

            if (isset($nombres[$clave])) {
                $estacion = $nombres[$clave];
                $tipo = null;

                continue;
            }

            if (str_starts_with($clave, 'problematica') && strlen($clave) < 16) {
                $tipo = PtarHallazgo::PROBLEMATICA;

                continue;
            }

            if ((str_starts_with($clave, 'propuesta') || str_starts_with($clave, 'accion')) && strlen($clave) < 14) {
                $tipo = PtarHallazgo::PROPUESTA;

                continue;
            }

            if ($estacion === null || $tipo === null) {
                $salida['avisos'][] = __('Texto sin planta o rótulo, se omite: :t', ['t' => Str::limit($texto, 60)]);

                continue;
            }

            $salida['hallazgos'][] = ['estacion' => $estacion, 'tipo' => $tipo, 'descripcion' => trim($texto)];
        }

        return $salida;
    }

    /**
     * @param  list<array{estacion: string, tipo: string, descripcion: string}>  $hallazgos
     * @return array{creados: int, omitidos: int, avisos: list<string>}
     */
    public function aplicar(array $hallazgos, string $fecha): array
    {
        PtarPlanta::sincronizar();
        $plantas = PtarPlanta::query()->with('estacion')->get()->keyBy(fn (PtarPlanta $p) => $p->estacion->nombre);
        $resultado = ['creados' => 0, 'omitidos' => 0, 'avisos' => []];

        foreach ($hallazgos as $h) {
            $planta = $plantas->get($h['estacion']);

            if (! $planta instanceof PtarPlanta) {
                $resultado['avisos'][] = __('Sin planta para :e; se omite.', ['e' => $h['estacion']]);

                continue;
            }

            $nuevo = PtarHallazgo::query()->firstOrCreate(
                ['planta_id' => $planta->id, 'tipo' => $h['tipo'], 'descripcion' => $h['descripcion']],
                ['estado' => PtarHallazgo::ABIERTO, 'prioridad' => 'media', 'fecha_detectado' => $fecha],
            );

            $resultado[$nuevo->wasRecentlyCreated ? 'creados' : 'omitidos']++;
        }

        return $resultado;
    }

    /** @return list<string> */
    private function parrafos(string $ruta): array
    {
        $zip = new ZipArchive;
        if ($zip->open($ruta) !== true || ($xml = $zip->getFromName('word/document.xml')) === false) {
            throw new RuntimeException(__('No se pudo leer el documento de Word.'));
        }
        $zip->close();

        $doc = new DOMDocument;
        $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $textos = [];
        foreach ($xpath->query('//w:body//w:p') as $p) {
            $textos[] = trim((string) preg_replace('/\s+/u', ' ', $xpath->evaluate('string(.)', $p)));
        }

        return $textos;
    }

    private function normalizar(string $texto): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($texto))));
    }
}
