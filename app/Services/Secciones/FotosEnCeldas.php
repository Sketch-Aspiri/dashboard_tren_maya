<?php

namespace App\Services\Secciones;

use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use SimpleXMLElement;
use ZipArchive;

/**
 * Fotos "dentro de la celda" de un .xlsx (Insertar > Imágenes > Colocar en
 * celda). Excel las guarda como valores enriquecidos (xl/richData) y
 * PhpSpreadsheet las lee como #VALUE!, así que se resuelven aquí directamente
 * del ZIP: celda (vm) -> xl/metadata.xml -> rdrichvalue.xml -> richValueRel
 * -> xl/media/imagenN.
 *
 * No descomprime todo el archivo: solo abre las partes XML necesarias y las
 * imágenes que realmente están en una celda.
 */
final class FotosEnCeldas
{
    private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const NS_RICHDATA = 'http://schemas.microsoft.com/office/spreadsheetml/2017/richdata';

    private const NS_REL = 'http://schemas.openxmlformats.org/package/2006/relationships';

    private const NS_DOC_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const NS_RICHVALUEREL = 'http://schemas.microsoft.com/office/spreadsheetml/2022/richvaluerel';

    private const MAX_XML_BYTES = 20 * 1024 * 1024;

    public function __construct(private readonly FotoService $fotos) {}

    /**
     * @return array<string, list<array{fila: int, columna: int, foto: string}>> por nombre de hoja
     */
    public function leer(string $ruta): array
    {
        $zip = new ZipArchive;

        if ($zip->open($ruta, ZipArchive::RDONLY) !== true) {
            return [];
        }

        try {
            return $this->leerDesde($zip);
        } catch (\Throwable $e) {
            Log::warning('No se pudieron leer las fotos en celdas del Excel.', ['error' => $e->getMessage()]);

            return [];
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array<string, list<array{fila: int, columna: int, foto: string}>>
     */
    private function leerDesde(ZipArchive $zip): array
    {
        $imagenPorVm = $this->imagenPorVm($zip);

        if ($imagenPorVm === []) {
            return [];
        }

        $guardadas = [];
        $resultado = [];

        foreach ($this->hojas($zip) as $nombreHoja => $parteHoja) {
            $xml = $this->parte($zip, $parteHoja);

            if ($xml === null || preg_match_all('/<c r="([A-Z]+)(\d+)"[^>]*\svm="(\d+)"/', $xml, $celdas, PREG_SET_ORDER) < 1) {
                continue;
            }

            foreach ($celdas as [, $letras, $fila, $vm]) {
                $media = $imagenPorVm[(int) $vm] ?? null;

                if ($media === null) {
                    continue;
                }

                $guardadas[$media] ??= $this->guardarMedia($zip, $media);

                if ($guardadas[$media] !== null) {
                    $resultado[$nombreHoja][] = [
                        'fila' => (int) $fila,
                        'columna' => Coordinate::columnIndexFromString($letras),
                        'foto' => $guardadas[$media],
                    ];
                }
            }
        }

        return $resultado;
    }

    /**
     * Ruta dentro del ZIP de la imagen de cada índice "vm" (1, 2, 3...) de celda.
     *
     * @return array<int, string>
     */
    private function imagenPorVm(ZipArchive $zip): array
    {
        $metadata = $this->xml($zip, 'xl/metadata.xml');
        $valores = $this->xml($zip, 'xl/richData/rdrichvalue.xml');
        $estructuras = $this->xml($zip, 'xl/richData/rdrichvaluestructure.xml');
        $relaciones = $this->xml($zip, 'xl/richData/richValueRel.xml');
        $destinos = $this->destinos($zip, 'xl/richData/_rels/richValueRel.xml.rels');

        if (! $metadata || ! $valores || ! $estructuras || ! $relaciones || $destinos === []) {
            return [];
        }

        $tipoRico = $this->indiceTipoMetadata($metadata);
        $valorRicoDeBloque = $this->valoresRicosDeBloques($metadata);
        $posicionImagen = $this->posicionImagenPorEstructura($estructuras);
        $idRelacion = [];

        foreach ($this->lista($relaciones->children(self::NS_RICHVALUEREL)->rel) as $rel) {
            $idRelacion[] = (string) $rel->attributes(self::NS_DOC_REL)['id'];
        }

        $imagenPorValorRico = [];

        foreach ($this->lista($valores->children(self::NS_RICHDATA)->rv) as $indice => $rv) {
            $posicion = $posicionImagen[(int) $this->attr($rv, 's')] ?? null;
            $campos = $this->lista($rv->children(self::NS_RICHDATA)->v);

            if ($posicion === null || ! isset($campos[$posicion])) {
                continue;
            }

            $destino = $destinos[$idRelacion[(int) $campos[$posicion]] ?? ''] ?? null;

            if ($destino !== null) {
                $imagenPorValorRico[$indice] = $destino;
            }
        }

        $resultado = [];

        // valueMetadata: el bloque n (1-based) es el "vm" de las celdas.
        foreach ($this->lista($metadata->children(self::NS_MAIN)->valueMetadata->bk) as $n => $bloque) {
            $rc = $bloque->rc;

            if ((int) $this->attr($rc, 't') !== $tipoRico) {
                continue;
            }

            $valorRico = $valorRicoDeBloque[(int) $this->attr($rc, 'v')] ?? null;

            if ($valorRico !== null && isset($imagenPorValorRico[$valorRico])) {
                $resultado[$n + 1] = $imagenPorValorRico[$valorRico];
            }
        }

        return $resultado;
    }

    /** Posición (1-based) del tipo XLRICHVALUE en metadataTypes. */
    private function indiceTipoMetadata(SimpleXMLElement $metadata): int
    {
        foreach ($this->lista($metadata->children(self::NS_MAIN)->metadataTypes->metadataType) as $i => $tipo) {
            if ((string) $this->attr($tipo, 'name') === 'XLRICHVALUE') {
                return $i + 1;
            }
        }

        return 0;
    }

    /**
     * futureMetadata XLRICHVALUE: índice de bloque -> índice del valor rico (rvb i).
     *
     * @return array<int, int>
     */
    private function valoresRicosDeBloques(SimpleXMLElement $metadata): array
    {
        $bloques = [];

        foreach ($this->lista($metadata->children(self::NS_MAIN)->futureMetadata) as $futuro) {
            if ((string) $this->attr($futuro, 'name') !== 'XLRICHVALUE') {
                continue;
            }

            foreach ($this->lista($futuro->bk) as $i => $bloque) {
                $rvb = $bloque->extLst->ext->children(self::NS_RICHDATA)->rvb;

                if ($this->attr($rvb, 'i') !== '') {
                    $bloques[$i] = (int) $this->attr($rvb, 'i');
                }
            }
        }

        return $bloques;
    }

    /**
     * Para cada estructura de valor rico, en qué posición viene el
     * identificador de imagen local.
     *
     * @return array<int, int>
     */
    private function posicionImagenPorEstructura(SimpleXMLElement $estructuras): array
    {
        $posiciones = [];

        foreach ($this->lista($estructuras->children(self::NS_RICHDATA)->s) as $i => $estructura) {
            foreach ($this->lista($estructura->children(self::NS_RICHDATA)->k) as $posicion => $clave) {
                if ((string) $this->attr($clave, 'n') === '_rvRel:LocalImageIdentifier') {
                    $posiciones[$i] = $posicion;
                }
            }
        }

        return $posiciones;
    }

    /**
     * @return array<string, string> nombre de hoja => parte XML dentro del ZIP
     */
    private function hojas(ZipArchive $zip): array
    {
        $libro = $this->xml($zip, 'xl/workbook.xml');
        $destinos = $this->destinos($zip, 'xl/_rels/workbook.xml.rels');
        $hojas = [];

        foreach ($libro === null ? [] : $this->lista($libro->children(self::NS_MAIN)->sheets->sheet) as $hoja) {
            $destino = $destinos[(string) $hoja->attributes(self::NS_DOC_REL)['id']] ?? null;

            if ($destino !== null) {
                $hojas[$this->attr($hoja, 'name')] = $destino;
            }
        }

        return $hojas;
    }

    /**
     * Relaciones de un .rels: id => ruta absoluta dentro del ZIP.
     *
     * @return array<string, string>
     */
    private function destinos(ZipArchive $zip, string $parteRels): array
    {
        $rels = $this->xml($zip, $parteRels);
        $base = dirname(dirname($parteRels));
        $destinos = [];

        foreach ($rels === null ? [] : $this->lista($rels->children(self::NS_REL)->Relationship) as $rel) {
            $destinos[$this->attr($rel, 'Id')] = $this->resolver($base, $this->attr($rel, 'Target'));
        }

        return $destinos;
    }

    private function resolver(string $base, string $destino): string
    {
        $partes = str_starts_with($destino, '/') ? [] : explode('/', $base === '.' ? '' : $base);

        foreach (explode('/', ltrim($destino, '/')) as $segmento) {
            match ($segmento) {
                '', '.' => null,
                '..' => array_pop($partes),
                default => $partes[] = $segmento,
            };
        }

        return implode('/', array_filter($partes, fn ($parte) => $parte !== ''));
    }

    private function guardarMedia(ZipArchive $zip, string $media): ?string
    {
        $contenido = $zip->getFromName($media, FotoService::MAX_BYTES + 1);

        return $contenido === false ? null : $this->fotos->guardar($contenido);
    }

    /**
     * Los hijos repetidos de un nodo SimpleXML como lista indexada (foreach
     * directo devuelve el nombre del elemento como llave, no la posición).
     *
     * @return list<SimpleXMLElement>
     */
    private function lista(?SimpleXMLElement $nodos): array
    {
        return $nodos === null ? [] : array_values(iterator_to_array($nodos, false));
    }

    /** Atributo sin espacio de nombres (dentro de children($ns) ['x'] no lo encuentra). */
    private function attr(SimpleXMLElement $nodo, string $nombre): string
    {
        return (string) ($nodo->attributes()[$nombre] ?? '');
    }

    private function parte(ZipArchive $zip, string $nombre): ?string
    {
        $estado = $zip->statName($nombre);

        if ($estado === false || $estado['size'] > self::MAX_XML_BYTES) {
            return null;
        }

        $contenido = $zip->getFromName($nombre);

        return $contenido === false ? null : $contenido;
    }

    private function xml(ZipArchive $zip, string $nombre): ?SimpleXMLElement
    {
        $contenido = $this->parte($zip, $nombre);

        if ($contenido === null) {
            return null;
        }

        $anterior = libxml_use_internal_errors(true);
        // LIBXML_NONET: nunca resolver entidades externas por red.
        $xml = simplexml_load_string($contenido, SimpleXMLElement::class, LIBXML_NONET);
        libxml_use_internal_errors($anterior);

        return $xml === false ? null : $xml;
    }
}
