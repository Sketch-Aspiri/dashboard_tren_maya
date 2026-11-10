<?php

namespace App\Http\Controllers;

use App\Http\Requests\GenerarComprobacionRequest;
use App\Models\Factura;
use App\Services\ComprobacionPdfService;
use App\Services\Documentos\ConversionPdfException;
use App\Services\SolicitudInsumoService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * PDF de comprobación (factura + hoja de validación, dos veces) de una factura
 * o de todas las del mes. Con ?ver=1 se abre en el navegador (vista previa);
 * sin él se descarga. La lectura es la misma que la de las facturas.
 */
class FacturaComprobacionController extends Controller
{
    private const CON_RELACIONES = ['conceptos.partida.solicitud.estacion', 'estacion'];

    public function __construct(private readonly ComprobacionPdfService $pdf) {}

    public function factura(GenerarComprobacionRequest $request, Factura $factura): Response|RedirectResponse
    {
        $factura->load(self::CON_RELACIONES);

        return $this->responder($request, collect([$factura]), 'COMPROBACION_'.($factura->referencia() ?: $factura->uuid), ['factura' => $factura->id]);
    }

    public function mes(GenerarComprobacionRequest $request, SolicitudInsumoService $solicitudes): Response|RedirectResponse
    {
        $objetivo = $solicitudes->mesObjetivo();
        $anio = (int) ($request->validated('anio') ?? $objetivo['anio']);
        $mes = (int) ($request->validated('mes') ?? $objetivo['mes']);

        $facturas = Factura::query()->with(self::CON_RELACIONES)->where('anio', $anio)->where('mes', $mes)
            ->orderBy('fecha')->orderBy('id')->get()
            ->sortBy(fn (Factura $f) => $f->estacion?->orden ?? 999)->values();

        return $this->responder($request, $facturas, sprintf('COMPROBACION_%d-%02d', $anio, $mes), ['anio' => $anio, 'mes' => $mes]);
    }

    /**
     * @param  Collection<int, Factura>  $facturas
     * @param  array<string, int>  $contexto
     */
    private function responder(GenerarComprobacionRequest $request, $facturas, string $nombre, array $contexto): Response|RedirectResponse
    {
        try {
            $contenido = $this->pdf->generar($facturas, $this->pdf->firmantes($request->validated('firmantes') ?? []));
        } catch (InvalidArgumentException|ConversionPdfException|DomainException $e) {
            // La vista previa pide el PDF con fetch y muestra el aviso en la misma ficha.
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 422)
                : back()->withErrors(['comprobacion' => $e->getMessage()]);
        }

        $ver = $request->boolean('ver');
        activity('factura')->causedBy($request->user())->withProperties($contexto + ['ver' => $ver])->log('comprobacion pdf generada');

        return response($contenido, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($ver ? 'inline' : 'attachment').'; filename="'.preg_replace('/[^A-Za-z0-9_.-]/', '_', $nombre).'.pdf"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
