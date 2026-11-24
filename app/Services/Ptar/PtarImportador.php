<?php

namespace App\Services\Ptar;

use App\Models\Estacion;
use App\Models\PtarEquipo;
use App\Models\PtarEvidencia;
use App\Models\PtarPlanta;
use App\Models\PtarRegistro;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Carga el Excel de estatus de las PTAR de la Zona. Cada hoja mensual ("JULIO 2026") trae un bloque de 5 filas por
 * planta: modo de operación (columnas E-G), norma, equipos con estatus (I-J), observaciones del equipo (K),
 * actividades con su última fecha (L-M), observaciones generales (N y P) y las fotos incrustadas.
 * Se localiza cada bloque por la fila donde aparece la estación (columna D) y se identifica la planta por su
 * número (columna B). Las hojas con otro formato (el histórico de JUNIO, los informes de obra) se omiten.
 * Los meses anteriores al último quedan aprobados y el último en borrador; no pisa registros existentes.
 */
final class PtarImportador
{
    private const MESES = ['enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6, 'julio' => 7, 'agosto' => 8, 'septiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12];

    private const COL_NUMERO = 2;

    private const COL_ESTACION = 4;

    private const COL_AUTOMATICO = 5;

    private const COL_MANUAL = 6;

    private const COL_FUERA = 7;

    private const COL_EQUIPO_ESTATUS = 10;

    private const COL_OBS_EQUIPO = 11;

    private const COL_ACTIVIDAD = 12;

    private const COL_FECHA = 13;

    private const COL_OBS_MES_ANTERIOR = 14;

    private const COL_OBS_MES = 16;

    private const FILA_ENCABEZADO = 6;

    private const PRIMERA_FILA = 8;

    /**
     * @return array{registros: list<array<string, mixed>>, avisos: list<string>}
     */
    public function leer(string $ruta): array
    {
        $libro = IOFactory::load($ruta);
        $salida = ['registros' => [], 'avisos' => []];
        $hojas = [];

        foreach ($libro->getWorksheetIterator() as $hoja) {
            $titulo = trim($hoja->getTitle());

            if (preg_match('/^([\p{L}]+)\s+(\d{4})$/u', $titulo, $m) !== 1 || ! isset(self::MESES[mb_strtolower($m[1])])) {
                continue;
            }

            if (! $this->tieneFormatoDeBloques($hoja)) {
                $salida['avisos'][] = __('Hoja ":h": formato histórico distinto; se omite.', ['h' => $titulo]);

                continue;
            }

            $hojas[((int) $m[2]) * 12 + self::MESES[mb_strtolower($m[1])]] = [$hoja, (int) $m[2], self::MESES[mb_strtolower($m[1])], $titulo];
        }

        ksort($hojas);
        $ultimo = $hojas === [] ? 0 : array_key_last($hojas);

        foreach ($hojas as $clave => [$hoja, $anio, $mes, $titulo]) {
            foreach ($this->bloques($hoja) as $bloque) {
                $registro = $this->leerBloque($hoja, $bloque, $anio, $mes, $titulo, $salida['avisos']);

                if ($registro !== null) {
                    $salida['registros'][] = $registro + ['estado' => $clave === $ultimo ? PtarRegistro::BORRADOR : PtarRegistro::APROBADA];
                }
            }
        }

        return $salida;
    }

    /**
     * Crea los registros leídos. Devuelve cuántos creó, cuántos omitió por existir ya y cuántas fotos guardó.
     *
     * @param  list<array<string, mixed>>  $registros  Salida de leer().
     * @return array{creados: int, omitidos: int, fotos: int, avisos: list<string>}
     */
    public function aplicar(array $registros): array
    {
        PtarPlanta::sincronizar();
        $resultado = ['creados' => 0, 'omitidos' => 0, 'fotos' => 0, 'avisos' => []];
        $guardadas = [];

        try {
            DB::transaction(function () use ($registros, &$resultado, &$guardadas) {
                foreach ($registros as $datos) {
                    $this->crear($datos, $resultado, $guardadas);
                }
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($guardadas);

            throw $e;
        }

        return $resultado;
    }

    /**
     * @param  array<string, mixed>  $datos
     * @param  array{creados: int, omitidos: int, fotos: int, avisos: list<string>}  $resultado
     * @param  list<string>  $guardadas
     */
    private function crear(array $datos, array &$resultado, array &$guardadas): void
    {
        if (PtarRegistro::query()->where(['estacion_id' => $datos['estacion_id'], 'anio' => $datos['anio'], 'mes' => $datos['mes']])->exists()) {
            $resultado['omitidos']++;

            return;
        }

        $registro = PtarRegistro::query()->create([
            'estacion_id' => $datos['estacion_id'], 'anio' => $datos['anio'], 'mes' => $datos['mes'], 'estado' => $datos['estado'],
            'modo' => $datos['modo'], 'modo_detalle' => $datos['modo_detalle'],
            'observaciones_equipos' => $datos['observaciones_equipos'], 'observaciones_generales' => $datos['observaciones_generales'],
        ]);

        foreach (array_keys(config('ptar.equipos')) as $clave) {
            $registro->equipos()->create(['equipo' => $clave, 'estatus' => $datos['equipos'][$clave] ?? PtarEquipo::SIN_DATO]);
        }

        foreach ($datos['actividades'] as $actividad) {
            $registro->actividades()->create($actividad);
        }

        $maximo = (int) config('ptar.max_evidencias');
        foreach ($datos['fotos'] as $i => $foto) {
            if ($i >= $maximo) {
                $resultado['avisos'][] = __(':e :m/:a: solo se guardan :n fotos; se omitieron las demás.', ['e' => $datos['estacion'], 'm' => $datos['mes'], 'a' => $datos['anio'], 'n' => $maximo]);
                break;
            }
            $ruta = 'ptar/evidencias/'.Str::uuid().'.'.$foto['extension'];
            Storage::disk('local')->put($ruta, $foto['contenido']);
            $guardadas[] = $ruta;
            $registro->evidencias()->create([
                'ruta' => $ruta, 'nombre' => mb_substr($datos['hoja'].' '.$datos['estacion'].' '.($i + 1).'.'.$foto['extension'], 0, 255),
                'origen' => PtarEvidencia::IMPORTACION,
            ]);
            $resultado['fotos']++;
        }

        $resultado['creados']++;
    }

    private function tieneFormatoDeBloques(Worksheet $hoja): bool
    {
        return str_starts_with(mb_strtolower($this->texto($hoja, 9, self::FILA_ENCABEZADO)), 'equipo')
            && str_starts_with(mb_strtolower($this->texto($hoja, self::COL_ESTACION, self::FILA_ENCABEZADO)), 'estaci');
    }

    /**
     * Bloques de la hoja: [fila inicial, fila final, número de planta].
     *
     * @return list<array{0: int, 1: int, 2: int}>
     */
    private function bloques(Worksheet $hoja): array
    {
        $ultima = $hoja->getHighestDataRow();
        $inicios = [];

        for ($fila = self::PRIMERA_FILA; $fila <= $ultima; $fila++) {
            if ($this->texto($hoja, self::COL_ESTACION, $fila) !== '' && ctype_digit($this->texto($hoja, self::COL_NUMERO, $fila))) {
                $inicios[] = [$fila, (int) $this->texto($hoja, self::COL_NUMERO, $fila)];
            }
        }

        $bloques = [];
        foreach ($inicios as $i => [$inicio, $numero]) {
            $bloques[] = [$inicio, isset($inicios[$i + 1]) ? $inicios[$i + 1][0] - 1 : $ultima, $numero];
        }

        return $bloques;
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $bloque
     * @param  list<string>  $avisos
     * @return array<string, mixed>|null
     */
    private function leerBloque(Worksheet $hoja, array $bloque, int $anio, int $mes, string $titulo, array &$avisos): ?array
    {
        [$inicio, $fin, $numero] = $bloque;
        $nombreHoja = $this->texto($hoja, self::COL_ESTACION, $inicio);
        $nombre = collect(config('ptar.plantas'))->search(fn (array $p) => $p['numero'] === $numero);
        $estacion = $nombre === false ? null : Estacion::query()->where('nombre', $nombre)->first();

        if ($estacion === null) {
            $avisos[] = __('Hoja ":h": ":e" (No. :n) no tiene PTAR en el catálogo; se omite.', ['h' => $titulo, 'e' => $nombreHoja, 'n' => $numero]);

            return null;
        }

        [$modo, $detalle] = PtarNormalizador::modo(
            $this->texto($hoja, self::COL_AUTOMATICO, $inicio),
            $this->texto($hoja, self::COL_MANUAL, $inicio),
            $this->texto($hoja, self::COL_FUERA, $inicio),
        );

        $equipos = [];
        for ($i = 0; $i < count(config('ptar.equipos')); $i++) {
            $equipos[PtarNormalizador::claveEquipo($i)] = PtarNormalizador::estatus($this->texto($hoja, self::COL_EQUIPO_ESTATUS, $inicio + $i));
        }

        $generales = $this->unir($hoja, self::COL_OBS_MES_ANTERIOR, $inicio, $fin);
        // Desde septiembre la hoja separa el mes anterior (N) del mes en curso (P); antes solo existe N.
        if ($mes >= 9 && str_starts_with(mb_strtolower($this->texto($hoja, self::COL_OBS_MES, self::FILA_ENCABEZADO)), 'observ')) {
            $delMes = $this->unir($hoja, self::COL_OBS_MES, $inicio, $fin);
            if ($delMes === null && $generales !== null) {
                $avisos[] = __(':e :h: sin observaciones propias del mes; se usó la columna del mes anterior.', ['e' => $estacion->nombre, 'h' => $titulo]);
            }
            $generales = $delMes ?? $generales;
        }

        return [
            'hoja' => $titulo, 'anio' => $anio, 'mes' => $mes, 'estacion_id' => $estacion->id, 'estacion' => $estacion->nombre,
            'modo' => $modo, 'modo_detalle' => $detalle, 'equipos' => $equipos,
            'observaciones_equipos' => $this->unir($hoja, self::COL_OBS_EQUIPO, $inicio, $fin),
            'observaciones_generales' => $generales,
            'actividades' => $this->actividades($hoja, $inicio, $fin),
            'fotos' => $this->fotos($hoja, $inicio, $fin),
        ];
    }

    /**
     * Una actividad por nombre; si se repite (p. ej. dos cárcamos) gana la de fecha más reciente.
     *
     * @return list<array{actividad: string, ultima_fecha: string|null, nota: string|null}>
     */
    private function actividades(Worksheet $hoja, int $inicio, int $fin): array
    {
        $porNombre = [];

        for ($fila = $inicio; $fila <= $fin; $fila++) {
            $nombre = PtarNormalizador::actividad($this->texto($hoja, self::COL_ACTIVIDAD, $fila));
            if ($nombre === '') {
                continue;
            }

            [$fecha, $nota] = PtarNormalizador::fecha($this->valor($hoja, self::COL_FECHA, $fila));
            $actual = $porNombre[mb_strtolower($nombre)] ?? null;

            if ($actual === null || ($fecha !== null && ($actual['ultima_fecha'] === null || $fecha > $actual['ultima_fecha']))) {
                $porNombre[mb_strtolower($nombre)] = ['actividad' => $nombre, 'ultima_fecha' => $fecha, 'nota' => $nota];
            }
        }

        return array_values($porNombre);
    }

    /**
     * Fotos cuyo ancla (celda inicial) cae dentro del bloque; se omiten los logotipos del encabezado y las repetidas.
     *
     * @return list<array{extension: string, contenido: string}>
     */
    private function fotos(Worksheet $hoja, int $inicio, int $fin): array
    {
        $fotos = [];

        foreach ($hoja->getDrawingCollection() as $dibujo) {
            $fila = Coordinate::coordinateFromString($dibujo->getCoordinates())[1];
            if ($fila < $inicio || $fila > $fin || ! $dibujo instanceof Drawing) {
                continue;
            }

            $contenido = @file_get_contents($dibujo->getPath());
            if ($contenido === false || $contenido === '') {
                continue;
            }

            $extension = strtolower($dibujo->getExtension()) === 'jpeg' ? 'jpg' : strtolower($dibujo->getExtension());
            // Solo imágenes que la captura también acepta: nunca SVG ni otro formato que pueda llevar scripts.
            if (! in_array($extension, ['jpg', 'png'], true)) {
                continue;
            }

            $fotos[md5($contenido)] ??= ['extension' => $extension, 'contenido' => $contenido];
        }

        return array_values($fotos);
    }

    /** Une los textos no vacíos de una columna dentro del bloque (celdas combinadas o párrafos en varias filas). */
    private function unir(Worksheet $hoja, int $columna, int $inicio, int $fin): ?string
    {
        $partes = [];

        for ($fila = $inicio; $fila <= $fin; $fila++) {
            $texto = $this->texto($hoja, $columna, $fila);
            if ($texto !== '') {
                $partes[] = $texto;
            }
        }

        return $partes === [] ? null : implode("\n\n", $partes);
    }

    private function texto(Worksheet $hoja, int $columna, int $fila): string
    {
        $valor = $this->valor($hoja, $columna, $fila);

        return $valor instanceof \DateTimeInterface ? $valor->format('Y-m-d') : trim((string) $valor);
    }

    private function valor(Worksheet $hoja, int $columna, int $fila): \DateTimeInterface|string|null
    {
        $celda = $hoja->getCell([$columna, $fila]);
        $valor = $celda->getValue();

        if ($valor instanceof RichText) {
            return trim(str_replace("\u{a0}", ' ', $valor->getPlainText()));
        }

        if (is_numeric($valor) && Date::isDateTime($celda)) {
            return Date::excelToDateTimeObject($valor);
        }

        if (! is_scalar($valor)) {
            return null;
        }

        $texto = str_replace("\u{a0}", ' ', (string) $valor);

        return str_starts_with($texto, '=') ? null : trim($texto);
    }
}
