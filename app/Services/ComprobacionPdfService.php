<?php

namespace App\Services;

use App\Models\Factura;
use App\Models\FacturaConcepto;
use App\Services\Documentos\ConvertidorPdf;
use App\Services\Documentos\DirectorioTemporal;
use App\Services\Documentos\ImportadorFirma;
use App\Services\Documentos\UnidorPdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PhpOffice\PhpWord\TemplateProcessor;

/**
 * PDF de comprobación de una factura: la factura, su hoja de validación, la
 * factura otra vez (bloque "XML") y la hoja de validación del XML. La hoja se
 * llena en la plantilla Word (resources/documentos/validacion_factura.docx) y
 * se convierte con LibreOffice; las hojas ya convertidas se guardan para no
 * repetir la conversión, que es lenta.
 */
final class ComprobacionPdfService
{
    public const FACTURA = 'FACTURA';

    public const XML = 'XML';

    public function __construct(
        private readonly ConvertidorPdf $convertidor,
        private readonly UnidorPdf $unidor,
        private readonly DenominacionPartidas $denominaciones,
        private readonly ImportadorFirma $importadorFirma,
    ) {}

    /**
     * Todo lo que cambia de una factura a otra en la hoja de validación.
     *
     * @return array{uuid: string, estaciones: string, importe: string, letra: string, partidas: string, sinPartidas: bool}
     */
    public function datos(Factura $factura): array
    {
        $factura->loadMissing(['conceptos.partida.solicitud.estacion', 'estacion']);
        $partidas = $this->partidas($factura);

        return [
            'uuid' => strtoupper((string) $factura->uuid),
            'estaciones' => $this->textoEstaciones($factura),
            'importe' => '$ '.number_format((float) $factura->total, 2),
            'letra' => ImporteConLetra::convertir((float) $factura->total),
            'partidas' => $this->unirConY($partidas->map(fn (string $c) => $c.' "'.$this->denominaciones->de($c).'"')->all()),
            'sinPartidas' => $partidas->isEmpty(),
        ];
    }

    /**
     * Párrafo de la hoja de validación, igual al de la plantilla.
     */
    public function texto(Factura $factura, string $tipo): string
    {
        $d = $this->datos($factura);
        $sujeto = $tipo === self::XML ? 'El presente XML' : 'La presente factura';

        return $sujeto.' con folio fiscal '.$d['uuid'].' ampara la compra de insumos destinados a cubrir las necesidades de '
            .$d['estaciones'].', por un importe de '.$d['importe'].' ('.$d['letra'].'), afectando la partida presupuestal '.$d['partidas'];
    }

    public function titulo(string $tipo): string
    {
        return $tipo === self::XML ? 'VALIDAN EL PRESENTE XML:' : 'VALIDAN LA PRESENTE FACTURA:';
    }

    /**
     * Cargo y nombre de quien recibe y de quien autoriza: los de la
     * configuración, salvo lo que se haya capturado en pantalla.
     *
     * @param  array<string, mixed>  $capturados  ['recibio' => ['cargo', 'nombre', 'firma'], 'autorizo' => [...]]
     * @return array<string, array{cargo: string, nombre: string, firma: ?UploadedFile}>
     */
    public function firmantes(array $capturados = []): array
    {
        $resultado = [];

        foreach (config('insumos.comprobacion.firmantes') as $clave => $porDefecto) {
            $dato = (array) ($capturados[$clave] ?? []);
            $firma = $dato['firma'] ?? null;

            $resultado[$clave] = [
                'cargo' => trim((string) ($dato['cargo'] ?? '')) ?: $porDefecto['cargo'],
                'nombre' => trim((string) ($dato['nombre'] ?? '')) ?: $porDefecto['nombre'],
                'firma' => $firma instanceof UploadedFile ? $firma : null,
            ];
        }

        return $resultado;
    }

    /**
     * PDF (contenido) con la comprobación de las facturas, en el orden recibido.
     *
     * @param  Collection<int, Factura>  $facturas
     * @param  array<string, array{cargo: string, nombre: string, firma: ?UploadedFile}>  $firmantes  los de firmantes()
     *
     * @throws InvalidArgumentException Si no hay facturas o a alguna le falta su PDF.
     */
    public function generar(Collection $facturas, array $firmantes = []): string
    {
        if ($facturas->isEmpty()) {
            throw new InvalidArgumentException('No hay facturas para comprobar.');
        }

        $faltantes = $facturas->filter(fn (Factura $f) => $f->pdf_ruta === null || ! Storage::disk('local')->exists($f->pdf_ruta));
        if ($faltantes->isNotEmpty()) {
            throw new InvalidArgumentException('Falta el PDF de la factura '.$faltantes->map(fn (Factura $f) => $f->referencia() ?: $f->uuid)->implode(', ').'. Súbelo en su ficha.');
        }

        $canceladas = $facturas->filter(fn (Factura $f) => $f->verificacion_sat === 'CANCELADO');
        if ($canceladas->isNotEmpty()) {
            throw new InvalidArgumentException('El SAT reporta como CANCELADA la factura '.$canceladas->map(fn (Factura $f) => $f->referencia() ?: $f->uuid)->implode(', ').': no se puede generar su comprobación.');
        }

        $trabajo = DirectorioTemporal::ruta().DIRECTORY_SEPARATOR.'cmp_'.Str::uuid()->toString();
        File::ensureDirectoryExists($trabajo, 0700, true);

        try {
            $firmantes = $firmantes ?: $this->firmantes();
            $imagenes = $this->prepararFirmas($firmantes, $trabajo);

            $partes = [];
            foreach ($facturas as $factura) {
                $original = Storage::disk('local')->path($factura->pdf_ruta);
                array_push(
                    $partes,
                    $original,
                    $this->hoja($factura, self::FACTURA, $firmantes, $imagenes, $trabajo),
                    $original,
                    $this->hoja($factura, self::XML, $firmantes, $imagenes, $trabajo),
                );
            }

            $destino = $trabajo.DIRECTORY_SEPARATOR.'comprobacion.pdf';
            $this->unidor->unir($partes, $destino);

            return (string) file_get_contents($destino);
        } finally {
            File::deleteDirectory($trabajo);
        }
    }

    /**
     * Las firmas subidas se re-codifican a PNG en la carpeta de trabajo; no se
     * guardan, hay que subirlas de nuevo al regenerar.
     *
     * @param  array<string, array{cargo: string, nombre: string, firma: ?UploadedFile}>  $firmantes
     * @return array<string, string> clave => ruta del PNG
     */
    private function prepararFirmas(array $firmantes, string $trabajo): array
    {
        $imagenes = [];

        foreach ($firmantes as $clave => $firmante) {
            if ($firmante['firma'] !== null) {
                $imagenes[$clave] = $this->importadorFirma->aPng($firmante['firma'], $trabajo.DIRECTORY_SEPARATOR.'firma_'.$clave.'.png');
            }
        }

        return $imagenes;
    }

    /**
     * Ruta de la hoja de validación ya convertida. Sin firma digital se
     * reutiliza mientras no cambien el texto, los cargos ni los nombres; con
     * firma se arma en la carpeta de trabajo y no se guarda.
     *
     * @param  array<string, array{cargo: string, nombre: string, firma: ?UploadedFile}>  $firmantes
     * @param  array<string, string>  $imagenes
     */
    private function hoja(Factura $factura, string $tipo, array $firmantes, array $imagenes, string $trabajo): string
    {
        $texto = $this->texto($factura, $tipo);
        $titulo = $this->titulo($tipo);
        $huella = substr(sha1($texto.$titulo.json_encode(array_map(fn (array $f) => [$f['cargo'], $f['nombre']], $firmantes))), 0, 12);
        $nombre = $factura->uuid.'_'.$tipo.'_'.$huella.'.pdf';

        if ($imagenes !== []) {
            $destino = $trabajo.DIRECTORY_SEPARATOR.$nombre;
            $this->convertir($texto, $titulo, $firmantes, $imagenes, $destino);

            return $destino;
        }

        $disco = Storage::disk('local');
        $ruta = 'facturas/validacion/'.$nombre;

        if (! $disco->exists($ruta)) {
            $disco->makeDirectory(dirname($ruta));
            $this->convertir($texto, $titulo, $firmantes, [], $disco->path($ruta));
        }

        return $disco->path($ruta);
    }

    /**
     * @param  array<string, array{cargo: string, nombre: string, firma: ?UploadedFile}>  $firmantes
     * @param  array<string, string>  $imagenes
     */
    private function convertir(string $texto, string $titulo, array $firmantes, array $imagenes, string $pdfDestino): void
    {
        $trabajo = DirectorioTemporal::ruta().DIRECTORY_SEPARATOR.'val_'.Str::uuid()->toString();
        File::ensureDirectoryExists($trabajo, 0700, true);

        try {
            $docx = $trabajo.DIRECTORY_SEPARATOR.'validacion.docx';
            $plantilla = new TemplateProcessor(resource_path('documentos/validacion_factura.docx'));
            $plantilla->setValue('TEXTO', $this->xml($texto));
            $plantilla->setValue('TITULO', $this->xml($titulo));
            $this->llenarFirmantes($plantilla, $firmantes, $imagenes);
            $plantilla->saveAs($docx);

            $this->convertidor->convertir($docx, $pdfDestino);
        } finally {
            File::deleteDirectory($trabajo);
        }
    }

    /**
     * @param  array<string, array{cargo: string, nombre: string, firma: ?UploadedFile}>  $firmantes
     * @param  array<string, string>  $imagenes
     */
    private function llenarFirmantes(TemplateProcessor $plantilla, array $firmantes, array $imagenes): void
    {
        foreach (array_keys(config('insumos.comprobacion.firmantes')) as $i => $clave) {
            $n = $i + 1;
            $plantilla->setValue('CARGO_'.$n, $this->xml($firmantes[$clave]['cargo']));
            $plantilla->setValue('NOMBRE_'.$n, $this->xml($firmantes[$clave]['nombre']));

            if (isset($imagenes[$clave])) {
                $plantilla->setImageValue('FIRMA_'.$n, ['path' => $imagenes[$clave], 'width' => 150, 'height' => 52, 'ratio' => true]);
            } else {
                $plantilla->setValue('FIRMA_'.$n, '');
            }
        }
    }

    private function xml(string $texto): string
    {
        return htmlspecialchars($texto, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * "las estaciones de Tulum, Chetumal y Nicolás Bravo, pertenecientes a la
     * Zona Oriente"; con muchas estaciones (o ninguna identificada) la fórmula
     * general de octubre.
     */
    private function textoEstaciones(Factura $factura): string
    {
        $nombres = $factura->conceptos->map(fn (FacturaConcepto $c) => $c->partida?->solicitud?->estacion)
            ->push($factura->estacion)->filter()->unique('id')->sortBy('orden')->pluck('nombre')->values()->all();

        if ($nombres === [] || count($nombres) > (int) config('insumos.comprobacion.maximo_estaciones_en_texto')) {
            return 'las estaciones pertenecientes a la Zona Oriente';
        }

        return 'las estaciones de '.$this->unirConY($nombres).', pertenecientes a la Zona Oriente';
    }

    /**
     * @return Collection<int, string>
     */
    private function partidas(Factura $factura): Collection
    {
        return $factura->conceptos->map(fn (FacturaConcepto $c) => $c->partida?->partida_final ?? $c->partida?->partida_presupuestal)
            ->filter()->unique()->sort()->values();
    }

    /**
     * @param  list<string>  $items
     */
    private function unirConY(array $items): string
    {
        if (count($items) <= 1) {
            return $items[0] ?? '';
        }

        return implode(', ', array_slice($items, 0, -1)).' y '.end($items);
    }
}
