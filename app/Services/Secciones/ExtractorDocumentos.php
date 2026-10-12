<?php

namespace App\Services\Secciones;

use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Cell;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use Smalot\PdfParser\Parser as PdfParser;
use ZipArchive;

/**
 * Lee documentos subidos a una sección, SIN IA:
 *  - tablas(): tablas encontradas (hojas de Excel/CSV, tablas de Word).
 *  - texto(): texto plano para la búsqueda de documentos y el asistente.
 *
 * Una tabla es ['nombre' => string, 'filas' => list<list<scalar|null>>],
 * tal cual viene en el documento (los encabezados los elige el usuario en
 * la vista previa). Cualquier fallo de lectura se registra y devuelve
 * vacío: el archivo de todos modos queda guardado como documento.
 */
final class ExtractorDocumentos
{
    private const EXTENSIONES_HOJA = ['xlsx', 'xls'];

    private const MIN_FILAS_TABLA = 2;

    /** Formatos Office que son contenedores ZIP (se revisan antes de abrirlos). */
    private const EXTENSIONES_ZIP = ['xlsx', 'docx', 'pptx'];

    /** Defensa contra "bombas zip": entradas y tamaño descomprimido máximos. */
    private const MAX_ENTRADAS_ZIP = 3000;

    private const MAX_DESCOMPRIMIDO_BYTES = 200 * 1024 * 1024;

    /**
     * Resultado de tablas() por archivo dentro de la misma petición: al
     * subir un Excel se necesita para el texto indexado y para la
     * importación, y no debe leerse dos veces (el servicio es "scoped").
     *
     * @var array<string, list<array{nombre: string, filas: list<list<mixed>>}>>
     */
    private array $cacheTablas = [];

    /**
     * @return list<array{nombre: string, filas: list<list<string|int|float|bool|null>>}>
     */
    public function tablas(string $ruta, string $extension): array
    {
        $llave = $ruta.'|'.(@filesize($ruta) ?: 0).'|'.(@filemtime($ruta) ?: 0);

        return $this->cacheTablas[$llave] ??= $this->leerTablas($ruta, $extension);
    }

    /**
     * @return list<array{nombre: string, filas: list<list<mixed>>}>
     */
    private function leerTablas(string $ruta, string $extension): array
    {
        if (! $this->contenedorSeguro($ruta, $extension)) {
            return [];
        }

        try {
            $tablas = match (true) {
                in_array($extension, self::EXTENSIONES_HOJA, true) => $this->tablasDeHojaDeCalculo($ruta, $extension),
                $extension === 'csv' => $this->tablasDeCsv($ruta),
                in_array($extension, ['docx', 'doc'], true) => $this->tablasDeWord($ruta, $extension),
                default => [],
            };
        } catch (\Throwable $e) {
            Log::warning('No se pudieron leer tablas del documento.', ['extension' => $extension, 'error' => $e->getMessage()]);

            return [];
        }

        return array_values(array_filter(
            array_map(fn (array $tabla) => ['nombre' => $tabla['nombre'], 'filas' => $this->recortar($tabla['filas'])], $tablas),
            fn (array $tabla) => count($tabla['filas']) >= self::MIN_FILAS_TABLA,
        ));
    }

    public function texto(string $ruta, string $extension): ?string
    {
        if (! $this->contenedorSeguro($ruta, $extension)) {
            return null;
        }

        try {
            $texto = match (true) {
                $extension === 'pdf' => (new PdfParser)->parseFile($ruta)->getText(),
                in_array($extension, ['docx', 'doc'], true) => $this->textoDeWord($ruta, $extension),
                $extension === 'pptx' => $this->textoDePowerPoint($ruta),
                in_array($extension, [...self::EXTENSIONES_HOJA, 'csv'], true) => $this->textoDeTablas($this->tablas($ruta, $extension)),
                default => null,
            };
        } catch (\Throwable $e) {
            Log::warning('No se pudo extraer texto del documento.', ['extension' => $extension, 'error' => $e->getMessage()]);

            return null;
        }

        if ($texto === null) {
            return null;
        }

        $texto = trim((string) preg_replace("/[ \t]+/u", ' ', mb_convert_encoding($texto, 'UTF-8', 'UTF-8')));

        return $texto === '' ? null : mb_substr($texto, 0, (int) config('secciones.max_texto_indexado'));
    }

    /**
     * @return list<array{nombre: string, filas: list<list<mixed>>}>
     */
    /**
     * Revisa un contenedor ZIP (xlsx/docx/pptx) sin descomprimirlo: si trae
     * demasiadas entradas o descomprimido pesaría demasiado, no se lee (el
     * archivo de todos modos queda guardado como documento).
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

        $total = 0;
        $seguro = $zip->numFiles <= self::MAX_ENTRADAS_ZIP;

        for ($i = 0; $seguro && $i < $zip->numFiles; $i++) {
            $total += (int) ($zip->statIndex($i)['size'] ?? 0);
            $seguro = $total <= self::MAX_DESCOMPRIMIDO_BYTES;
        }

        $zip->close();

        if (! $seguro) {
            Log::warning('Documento omitido de la lectura: contenedor ZIP demasiado grande descomprimido.', ['extension' => $extension]);
        }

        return $seguro;
    }

    private function tablasDeHojaDeCalculo(string $ruta, string $extension): array
    {
        // Lector explícito por extensión (no adivinar el formato por el contenido).
        $reader = SpreadsheetIOFactory::createReader($extension === 'xls' ? 'Xls' : 'Xlsx');
        $reader->setReadEmptyCells(false);
        $libro = $reader->load($ruta);

        $tablas = [];

        foreach ($libro->getWorksheetIterator() as $hoja) {
            if ($hoja->getSheetState() !== Worksheet::SHEETSTATE_VISIBLE) {
                continue;
            }

            $tablas[] = ['nombre' => $hoja->getTitle(), 'filas' => $this->filasDeHoja($hoja)];
        }

        $libro->disconnectWorksheets();

        return $tablas;
    }

    /**
     * @return list<list<mixed>>
     */
    private function filasDeHoja(Worksheet $hoja): array
    {
        $maxFila = min($hoja->getHighestDataRow(), $this->maxFilas());
        $maxColumna = min(Coordinate::columnIndexFromString($hoja->getHighestDataColumn()), (int) config('secciones.max_columnas'));

        $filas = [];

        for ($fila = 1; $fila <= $maxFila; $fila++) {
            $valores = [];

            for ($columna = 1; $columna <= $maxColumna; $columna++) {
                $valores[] = $hoja->cellExists([$columna, $fila]) ? $this->valorCelda($hoja, $columna, $fila) : null;
            }

            $filas[] = $valores;
        }

        return $filas;
    }

    private function valorCelda(Worksheet $hoja, int $columna, int $fila): string|int|float|bool|null
    {
        $celda = $hoja->getCell([$columna, $fila]);

        try {
            $valor = $celda->getCalculatedValue();
        } catch (\Throwable) {
            $valor = $celda->getOldCalculatedValue();
        }

        if ($valor instanceof RichText) {
            $valor = $valor->getPlainText();
        }

        if (is_numeric($valor) && ExcelDate::isDateTime($celda)) {
            return ExcelDate::excelToDateTimeObject((float) $valor)->format('Y-m-d');
        }

        if (is_float($valor) && floor($valor) === $valor && abs($valor) < PHP_INT_MAX) {
            return (int) $valor;
        }

        if (is_string($valor)) {
            $valor = trim($valor);

            return $valor === '' ? null : $valor;
        }

        return is_scalar($valor) ? $valor : null;
    }

    /**
     * @return list<array{nombre: string, filas: list<list<mixed>>}>
     */
    private function tablasDeCsv(string $ruta): array
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

        while (($fila = fgetcsv($flujo, null, $delimitador, '"', '')) !== false && count($filas) < $this->maxFilas()) {
            $filas[] = array_map(fn ($valor) => ($valor === null || trim($valor) === '') ? null : trim($valor), $fila);
        }

        fclose($flujo);

        return [['nombre' => 'CSV', 'filas' => $filas]];
    }

    /**
     * @return list<array{nombre: string, filas: list<list<mixed>>}>
     */
    private function tablasDeWord(string $ruta, string $extension): array
    {
        $documento = WordIOFactory::load($ruta, $extension === 'doc' ? 'MsDoc' : 'Word2007');
        $tablas = [];

        foreach ($documento->getSections() as $seccion) {
            foreach ($this->tablasEnContenedor($seccion) as $tabla) {
                $filas = [];

                foreach ($tabla->getRows() as $fila) {
                    $filas[] = array_map(
                        fn (Cell $celda) => ($texto = trim($this->textoDeContenedor($celda))) === '' ? null : $texto,
                        $fila->getCells(),
                    );
                }

                $tablas[] = ['nombre' => 'Tabla '.(count($tablas) + 1), 'filas' => $filas];
            }
        }

        return $tablas;
    }

    /**
     * @return list<Table>
     */
    private function tablasEnContenedor(AbstractContainer $contenedor): array
    {
        $tablas = [];

        foreach ($contenedor->getElements() as $elemento) {
            if ($elemento instanceof Table) {
                $tablas[] = $elemento;
            }
        }

        return $tablas;
    }

    private function textoDeWord(string $ruta, string $extension): string
    {
        $documento = WordIOFactory::load($ruta, $extension === 'doc' ? 'MsDoc' : 'Word2007');
        $partes = [];

        foreach ($documento->getSections() as $seccion) {
            $partes[] = $this->textoDeContenedor($seccion);
        }

        return implode("\n", $partes);
    }

    /**
     * Texto de cualquier elemento de PhpWord, recorriendo contenedores,
     * tablas y corridas de texto.
     */
    private function textoDeContenedor(object $elemento): string
    {
        if ($elemento instanceof Table) {
            return implode("\n", array_map(
                fn ($fila) => implode(' | ', array_map(fn (Cell $celda) => trim($this->textoDeContenedor($celda)), $fila->getCells())),
                $elemento->getRows(),
            ));
        }

        if (method_exists($elemento, 'getElements')) {
            $separador = $elemento instanceof AbstractContainer && ! $elemento instanceof TextRun ? "\n" : '';

            return implode($separador, array_map(fn ($hijo) => $this->textoDeContenedor($hijo), $elemento->getElements()));
        }

        if (method_exists($elemento, 'getTextObject')) {
            return $this->textoDeContenedor($elemento->getTextObject());
        }

        if (method_exists($elemento, 'getText')) {
            $texto = $elemento->getText();

            return is_string($texto) ? $texto : (is_object($texto) ? $this->textoDeContenedor($texto) : '');
        }

        return '';
    }

    private function textoDePowerPoint(string $ruta): ?string
    {
        $zip = new ZipArchive;

        if ($zip->open($ruta) !== true) {
            return null;
        }

        $diapositivas = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nombre = (string) $zip->getNameIndex($i);

            if (preg_match('#^ppt/slides/slide(\d+)\.xml$#', $nombre, $m)) {
                preg_match_all('#<a:t>(.*?)</a:t>#s', (string) $zip->getFromIndex($i), $textos);
                $diapositivas[(int) $m[1]] = html_entity_decode(implode(' ', $textos[1]), ENT_QUOTES | ENT_XML1);
            }
        }

        $zip->close();
        ksort($diapositivas);

        return implode("\n", $diapositivas);
    }

    /**
     * @param  list<array{nombre: string, filas: list<list<mixed>>}>  $tablas
     */
    private function textoDeTablas(array $tablas): string
    {
        return implode("\n", array_map(
            fn (array $tabla) => $tabla['nombre']."\n".implode("\n", array_map(
                fn (array $fila) => implode(' | ', array_map(fn ($valor) => (string) $valor, array_filter($fila, fn ($valor) => $valor !== null))),
                $tabla['filas'],
            )),
            $tablas,
        ));
    }

    /**
     * Quita filas totalmente vacías y las columnas vacías a la izquierda y
     * a la derecha (márgenes típicos de los Excel institucionales).
     *
     * @param  list<list<mixed>>  $filas
     * @return list<list<mixed>>
     */
    private function recortar(array $filas): array
    {
        $tieneValor = fn ($valor) => $valor !== null && $valor !== '';

        $filas = array_values(array_filter($filas, fn (array $fila) => array_filter($fila, $tieneValor) !== []));

        $inicio = PHP_INT_MAX;
        $fin = -1;

        foreach ($filas as $fila) {
            $conValor = array_keys(array_filter($fila, $tieneValor));
            $inicio = min($inicio, $conValor[0]);
            $fin = max($fin, end($conValor));
        }

        if ($fin < 0) {
            return [];
        }

        $ancho = $fin - $inicio + 1;

        return array_map(fn (array $fila) => array_pad(array_slice(array_values($fila), $inicio, $ancho), $ancho, null), $filas);
    }

    private function maxFilas(): int
    {
        return (int) config('secciones.max_filas_importacion') + 50;
    }
}
