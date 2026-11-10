<?php

namespace App\Services;

use App\Jobs\VerificarFacturaSatJob;
use App\Models\CotizacionRenglon;
use App\Models\Factura;
use App\Models\FacturaConcepto;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

/**
 * Carga de facturas CFDI desde su XML y ajustes manuales de sus vínculos. La
 * lectura la hace CfdiParser y las validaciones y comparaciones
 * ComprobacionService; aquí solo se persiste, se guardan los archivos en el
 * disco privado y se vuelve a analizar cuando una persona corrige un vínculo.
 */
final class FacturaService
{
    public function __construct(
        private readonly CfdiParser $parser,
        private readonly ComprobacionService $comprobacion,
    ) {}

    /**
     * @throws InvalidArgumentException Si el XML no es un CFDI válido o ya está cargado.
     */
    public function importar(UploadedFile $archivo, int $anio, int $mes, User $usuario, UploadedFile $pdf): Factura
    {
        $contenido = (string) file_get_contents($archivo->getRealPath());
        $datos = $this->parser->leer($contenido);

        if (Factura::query()->where('uuid', $datos['uuid'])->exists()) {
            throw new InvalidArgumentException('Esta factura ya está cargada (UUID '.$datos['uuid'].').');
        }

        $ruta = 'facturas/xml/'.$datos['uuid'].'.xml';
        $conceptos = $datos['conceptos'];
        unset($datos['conceptos'], $datos['version']);

        try {
            return DB::transaction(function () use ($datos, $conceptos, $anio, $mes, $usuario, $archivo, $ruta, $contenido, $pdf) {
                Storage::disk('local')->put($ruta, $contenido);

                $factura = Factura::query()->create($datos + [
                    'anio' => $anio,
                    'mes' => $mes,
                    'xml_ruta' => $ruta,
                    'xml_nombre' => mb_substr(basename($archivo->getClientOriginalName()), 0, 255),
                    'creada_por' => $usuario->id,
                ]);

                foreach ($conceptos as $i => $concepto) {
                    $factura->conceptos()->create($concepto + ['numero' => $i + 1]);
                }

                $this->adjuntarPdf($factura, $pdf);
                $this->comprobacion->analizar($factura);

                if (config('insumos.sat.consulta_automatica')) {
                    VerificarFacturaSatJob::dispatch($factura->id)->afterCommit();
                }

                return $factura->refresh();
            });
        } catch (Throwable $e) {
            Storage::disk('local')->delete($ruta);

            throw $e;
        }
    }

    /**
     * Pareja cada XML con su PDF por el nombre del archivo (sin extensión y sin
     * distinguir mayúsculas). Con un solo XML y un solo PDF se emparejan sin
     * importar el nombre.
     *
     * @param  list<UploadedFile>  $xmls
     * @param  list<UploadedFile>  $pdfs
     * @return array<int, UploadedFile> posición del XML => su PDF
     */
    public function emparejarPdf(array $xmls, array $pdfs): array
    {
        if (count($xmls) === 1 && count($pdfs) === 1) {
            return [array_key_first($xmls) => reset($pdfs)];
        }

        $porNombre = [];
        foreach ($pdfs as $pdf) {
            $porNombre[mb_strtolower(pathinfo($pdf->getClientOriginalName(), PATHINFO_FILENAME))] ??= $pdf;
        }

        $pares = [];
        foreach ($xmls as $i => $xml) {
            $nombre = mb_strtolower(pathinfo($xml->getClientOriginalName(), PATHINFO_FILENAME));
            if (isset($porNombre[$nombre])) {
                $pares[$i] = $porNombre[$nombre];
            }
        }

        return $pares;
    }

    public function adjuntarPdf(Factura $factura, UploadedFile $pdf): void
    {
        if ($factura->pdf_ruta !== null) {
            Storage::disk('local')->delete($factura->pdf_ruta);
        }

        $factura->update([
            'pdf_ruta' => $pdf->store('facturas/pdf', 'local'),
            'pdf_nombre' => mb_substr(basename($pdf->getClientOriginalName()), 0, 255),
        ]);
    }

    public function eliminar(Factura $factura): void
    {
        DB::transaction(function () use ($factura) {
            foreach ([$factura->xml_ruta, $factura->pdf_ruta] as $ruta) {
                if ($ruta !== null) {
                    Storage::disk('local')->delete($ruta);
                }
            }

            $factura->delete();
        });
    }

    /**
     * Cotización y estación elegidas a mano. Sin cotización (null) el sistema
     * la vuelve a buscar por notas, claves o contenido.
     */
    public function actualizarVinculos(Factura $factura, ?int $cotizacionId, ?int $estacionId): void
    {
        $factura->update([
            'cotizacion_id' => $cotizacionId,
            'vinculo_cotizacion' => $cotizacionId === null ? null : 'manual',
            'estacion_id' => $estacionId,
        ]);

        // Los vínculos automáticos de los conceptos se recalculan contra la cotización elegida.
        $factura->conceptos()->where(fn ($q) => $q->whereNull('vinculo')->orWhere('vinculo', '!=', 'manual'))
            ->update(['solicitud_insumo_partida_id' => null, 'cotizacion_renglon_id' => null, 'vinculo' => null]);

        $this->comprobacion->analizar($factura);
    }

    /**
     * Vincula a mano un concepto con una partida del mes (null = que el
     * sistema lo vuelva a buscar).
     */
    public function vincularConcepto(FacturaConcepto $concepto, ?int $partidaId): void
    {
        $factura = $concepto->factura;
        $renglon = $partidaId === null ? null : CotizacionRenglon::query()
            ->where('cotizacion_id', $factura->cotizacion_id)->where('solicitud_insumo_partida_id', $partidaId)->first();

        $concepto->update([
            'solicitud_insumo_partida_id' => $partidaId,
            'cotizacion_renglon_id' => $renglon?->id,
            'vinculo' => $partidaId === null ? null : 'manual',
        ]);

        $this->comprobacion->analizar($factura);
    }
}
