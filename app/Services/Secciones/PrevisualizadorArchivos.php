<?php

namespace App\Services\Secciones;

use App\Models\SeccionArchivo;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Cell\Cell as CeldaHoja;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Cell;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use ZipArchive;

/**
 * Vista previa dentro del sistema de un documento ya guardado. Solo lectura
 * y con límites de filas/columnas/caracteres. Independiente de la lógica de
 * importación: no usa ExtractorDocumentos.
 *
 * Devuelve siempre un arreglo con "tipo":
 *  - pdf | imagen        : se muestran en línea desde la ruta autorizada.
 *  - tabla               : filas de una hoja de Excel/CSV (+ lista de hojas).
 *  - documento           : texto y tablas de un Word.
 *  - texto               : texto plano.
 *  - no_soportado        : sin render posible (mensaje + descarga).
 *
 * La decisión se toma con la extensión validada al subir Y el tipo real del
 * contenido en disco; nunca con datos del cliente.
 */
final class PrevisualizadorArchivos
{
    private const EXTENSIONES_HOJA = ['xlsx', 'xls'];

    private const EXTENSIONES_ZIP = ['xlsx', 'docx', 'pptx'];

    private const MAX_ENTRADAS_ZIP = 3000;

    /** Entradas más chicas que esto no se juzgan por razón de compresión. */
    private const MIN_BYTES_PARA_RAZON = 1024 * 1024;

    public function __construct(private readonly ArchivoService $archivos) {}

    /**
     * @return array<string, mixed>
     */
    public function previsualizar(SeccionArchivo $archivo, int $hoja = 0): array
    {
        $ruta = $this->archivos->rutaAbsoluta($archivo);
        $extension = $archivo->extension;

        if (! is_file($ruta)) {
            return $this->noSoportado(__('El archivo ya no está disponible en el servidor.'));
        }

        if ($archivo->mimeEnLinea() !== null) {
            return $this->enLinea($archivo, $ruta);
        }

        if (! $this->archivos->puedeLeerse((int) filesize($ruta))) {
            return $this->noSoportado(__('El archivo es demasiado grande para mostrarlo aquí.'));
        }

        if (! $this->contenedorSeguro($ruta, $extension)) {
            return $this->noSoportado(__('No se puede mostrar este archivo de forma segura.'));
        }

        try {
            return match (true) {
                in_array($extension, self::EXTENSIONES_HOJA, true) => $this->hojaDeCalculo($ruta, $extension, $hoja),
                $extension === 'csv' => $this->csv($ruta),
                in_array($extension, ['docx', 'doc'], true) => $this->word($ruta, $extension),
                $extension === 'pptx' => $this->textoGuardado($archivo),
                in_array($extension, ['txt', 'log', 'md'], true) => $this->textoPlano($ruta),
                default => $this->noSoportado(__('Este tipo de archivo no tiene vista previa.')),
            };
        } catch (\Throwable $e) {
            Log::warning('No se pudo generar la vista previa del documento.', ['extension' => $extension, 'error' => $e->getMessage()]);

            return $this->noSoportado(__('No se pudo leer el archivo para mostrarlo.'));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function enLinea(SeccionArchivo $archivo, string $ruta): array
    {
        $mimeReal = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($ruta);

        if ($mimeReal !== $archivo->mimeEnLinea()) {
            return $this->noSoportado(__('El contenido del archivo no coincide con su tipo.'));
        }

        return ['tipo' => $archivo->extension === 'pdf' ? 'pdf' : 'imagen'];
    }

    /**
     * @return array<string, mixed>
     */
    private function noSoportado(string $mensaje): array
    {
        return ['tipo' => 'no_soportado', 'mensaje' => $mensaje];
    }

    /**
     * @return array<string, mixed>
     */
    private function hojaDeCalculo(string $ruta, string $extension, int $indiceHoja): array
    {
        $lector = SpreadsheetIOFactory::createReader($extension === 'xls' ? 'Xls' : 'Xlsx');
        $totales = [];

        foreach ($lector->listWorksheetInfo($ruta) as $info) {
            $totales[(string) $info['worksheetName']] = ['filas' => (int) $info['totalRows'], 'columnas' => (int) $info['totalColumns']];
        }

        $maxFilas = $this->maxFilas();
        $maxColumnas = $this->maxColumnas();

        // Se conservan los formatos (fechas) pero las fórmulas NO se
        // recalculan: solo se muestra el valor que el archivo ya trae guardado.
        $lector->setReadDataOnly(false);
        $lector->setReadFilter(new class($maxFilas, $maxColumnas) implements IReadFilter
        {
            public function __construct(private readonly int $maxFilas, private readonly int $maxColumnas) {}

            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row <= $this->maxFilas && Coordinate::columnIndexFromString($columnAddress) <= $this->maxColumnas;
            }
        });

        $libro = $lector->load($ruta);
        // Las hojas ocultas no se muestran (igual que al importar).
        $visibles = array_values(array_filter(
            $libro->getAllSheets(),
            fn (Worksheet $hoja) => $hoja->getSheetState() === Worksheet::SHEETSTATE_VISIBLE,
        ));

        if ($visibles === []) {
            $libro->disconnectWorksheets();

            return ['tipo' => 'tabla', 'hojas' => [], 'hoja' => 0, 'filas' => [], 'truncado' => false];
        }

        $indice = min(max($indiceHoja, 0), count($visibles) - 1);
        $total = $totales[$visibles[$indice]->getTitle()] ?? ['filas' => 0, 'columnas' => 0];
        $nombres = array_map(fn (Worksheet $visible) => $visible->getTitle(), $visibles);
        $filas = $this->filasDeHoja($visibles[$indice], $maxFilas, $maxColumnas);

        $libro->disconnectWorksheets();

        return [
            'tipo' => 'tabla',
            'hojas' => $nombres,
            'hoja' => $indice,
            'filas' => $this->rellenar($filas),
            'truncado' => $total['filas'] > $maxFilas || $total['columnas'] > $maxColumnas,
        ];
    }

    /**
     * @return list<list<string>>
     */
    private function filasDeHoja(Worksheet $hoja, int $maxFilas, int $maxColumnas): array
    {
        $filas = [];
        $ultimaColumna = min(Coordinate::columnIndexFromString($hoja->getHighestDataColumn()), $maxColumnas);

        for ($fila = 1; $fila <= min($hoja->getHighestDataRow(), $maxFilas); $fila++) {
            $valores = [];

            for ($columna = 1; $columna <= $ultimaColumna; $columna++) {
                $valores[] = $hoja->cellExists([$columna, $fila]) ? $this->textoCelda($hoja->getCell([$columna, $fila])) : '';
            }

            $filas[] = $valores;
        }

        return $filas;
    }

    /**
     * Texto de una celda sin evaluar fórmulas (una fórmula puede invocar
     * funciones con efectos externos): en celdas con fórmula se usa el
     * último valor calculado que guardó el archivo.
     */
    private function textoCelda(CeldaHoja $celda): string
    {
        try {
            $valor = $celda->isFormula() ? $celda->getOldCalculatedValue() : $celda->getValue();

            if ($valor instanceof RichText) {
                $valor = $valor->getPlainText();
            }

            if (is_bool($valor)) {
                return $valor ? 'VERDADERO' : 'FALSO';
            }

            if (! is_scalar($valor)) {
                return '';
            }

            $formato = $celda->getStyle()->getNumberFormat()->getFormatCode();

            return trim(is_numeric($valor) ? NumberFormat::toFormattedString($valor, $formato) : (string) $valor);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function csv(string $ruta): array
    {
        $contenido = (string) file_get_contents($ruta);
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido) ?? $contenido;

        if (! mb_check_encoding($contenido, 'UTF-8')) {
            $contenido = mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252');
        }

        $primeraLinea = strtok($contenido, "\n") ?: '';
        $delimitador = collect([',', ';', "\t", '|'])
            ->sortByDesc(fn (string $candidato) => substr_count($primeraLinea, $candidato))
            ->first();

        $flujo = fopen('php://temp', 'r+');
        fwrite($flujo, $contenido);
        rewind($flujo);

        $filas = [];
        $truncado = false;

        while (($fila = fgetcsv($flujo, null, $delimitador, '"', '')) !== false) {
            if (count($filas) >= $this->maxFilas()) {
                $truncado = true;
                break;
            }

            $truncado = $truncado || count($fila) > $this->maxColumnas();
            $filas[] = array_map(fn ($valor) => trim((string) $valor), array_slice($fila, 0, $this->maxColumnas()));
        }

        fclose($flujo);

        return ['tipo' => 'tabla', 'hojas' => [], 'hoja' => 0, 'filas' => $this->rellenar($filas), 'truncado' => $truncado];
    }

    /**
     * @return array<string, mixed>
     */
    private function word(string $ruta, string $extension): array
    {
        $documento = WordIOFactory::load($ruta, $extension === 'doc' ? 'MsDoc' : 'Word2007');
        $partes = [];
        $tablas = [];

        foreach ($documento->getSections() as $seccion) {
            foreach ($seccion->getElements() as $elemento) {
                if ($elemento instanceof Table) {
                    $tablas[] = ['nombre' => __('Tabla :n', ['n' => count($tablas) + 1]), 'filas' => $this->filasDeTablaWord($elemento)];

                    continue;
                }

                $partes[] = $this->textoDeElemento($elemento);
            }
        }

        $texto = trim(implode("\n", array_filter($partes, fn (string $parte) => trim($parte) !== '')));
        $limite = $this->maxTexto();

        return [
            'tipo' => 'documento',
            'texto' => mb_substr($texto, 0, $limite),
            'tablas' => array_map(fn (array $tabla) => ['nombre' => $tabla['nombre'], 'filas' => array_slice($tabla['filas'], 0, $this->maxFilas())], array_slice($tablas, 0, 20)),
            'truncado' => mb_strlen($texto) > $limite,
        ];
    }

    /**
     * @return list<list<string>>
     */
    private function filasDeTablaWord(Table $tabla): array
    {
        $filas = [];

        foreach ($tabla->getRows() as $fila) {
            $filas[] = array_slice(array_map(
                fn (Cell $celda) => trim($this->textoDeElemento($celda)),
                $fila->getCells(),
            ), 0, $this->maxColumnas());
        }

        return $this->rellenar($filas);
    }

    private function textoDeElemento(object $elemento): string
    {
        if ($elemento instanceof Table) {
            return implode("\n", array_map(fn (array $fila) => implode(' | ', $fila), $this->filasDeTablaWord($elemento)));
        }

        if (method_exists($elemento, 'getElements')) {
            $separador = $elemento instanceof AbstractContainer && ! $elemento instanceof TextRun ? "\n" : '';

            return implode($separador, array_map(fn ($hijo) => $this->textoDeElemento($hijo), $elemento->getElements()));
        }

        if (method_exists($elemento, 'getText')) {
            $texto = $elemento->getText();

            return is_string($texto) ? $texto : (is_object($texto) ? $this->textoDeElemento($texto) : '');
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function textoGuardado(SeccionArchivo $archivo): array
    {
        $texto = (string) $archivo->texto_extraido;

        if ($texto === '') {
            return $this->noSoportado(__('No se encontró texto para mostrar en este archivo.'));
        }

        return $this->texto($texto);
    }

    /**
     * @return array<string, mixed>
     */
    private function textoPlano(string $ruta): array
    {
        $contenido = (string) file_get_contents($ruta, false, null, 0, $this->maxTexto() * 4);

        if (! mb_check_encoding($contenido, 'UTF-8')) {
            $contenido = mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252');
        }

        return $this->texto($contenido);
    }

    /**
     * @return array<string, mixed>
     */
    private function texto(string $contenido): array
    {
        $limite = $this->maxTexto();

        return ['tipo' => 'texto', 'texto' => mb_substr($contenido, 0, $limite), 'truncado' => mb_strlen($contenido) > $limite];
    }

    /**
     * Revisa un contenedor ZIP sin descomprimirlo (defensa contra "bombas zip").
     */
    private function contenedorSeguro(string $ruta, string $extension): bool
    {
        if (! in_array($extension, self::EXTENSIONES_ZIP, true)) {
            return true;
        }

        $zip = new ZipArchive;

        if ($zip->open($ruta, ZipArchive::RDONLY) !== true) {
            return false;
        }

        $seguro = $zip->numFiles <= self::MAX_ENTRADAS_ZIP;
        $total = 0;
        $maxEntrada = (int) config('secciones.vista_previa_zip_entrada_mb') * 1024 * 1024;
        $maxTotal = (int) config('secciones.vista_previa_zip_total_mb') * 1024 * 1024;
        $maxRazon = (int) config('secciones.vista_previa_zip_ratio');

        for ($i = 0; $seguro && $i < $zip->numFiles; $i++) {
            $estado = $zip->statIndex($i) ?: [];
            $tamano = (int) ($estado['size'] ?? 0);
            $comprimido = max(1, (int) ($estado['comp_size'] ?? 0));
            $total += $tamano;

            $seguro = $tamano <= $maxEntrada
                && $total <= $maxTotal
                && ($tamano < self::MIN_BYTES_PARA_RAZON || intdiv($tamano, $comprimido) <= $maxRazon);
        }

        $zip->close();

        return $seguro;
    }

    /**
     * Iguala el ancho de todas las filas para pintar una tabla regular.
     *
     * @param  list<list<string>>  $filas
     * @return list<list<string>>
     */
    private function rellenar(array $filas): array
    {
        $ancho = $filas === [] ? 0 : max(array_map('count', $filas));

        return array_map(fn (array $fila) => array_pad(array_values($fila), $ancho, ''), $filas);
    }

    private function maxFilas(): int
    {
        return (int) config('secciones.vista_previa_filas');
    }

    private function maxColumnas(): int
    {
        return (int) config('secciones.vista_previa_columnas');
    }

    private function maxTexto(): int
    {
        return (int) config('secciones.vista_previa_caracteres');
    }
}
