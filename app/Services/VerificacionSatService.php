<?php

namespace App\Services;

use App\Models\Factura;
use App\Services\Sat\ConsultaSatException;
use App\Services\Sat\ConsultorSat;
use App\Services\Sat\ResultadoSat;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Verifica en el SAT si una factura sigue vigente y guarda el resultado en
 * `facturas.verificacion_sat`. Un fallo del SAT nunca rompe nada: la factura
 * queda en ERROR (se reintenta) y la comprobación avisa. Después de cada
 * consulta se vuelve a analizar la factura para actualizar sus hallazgos.
 */
final class VerificacionSatService
{
    public const NO_VERIFICADO = 'NO_VERIFICADO';

    public const ERROR = 'ERROR';

    public function __construct(
        private readonly ConsultorSat $consultor,
        private readonly ComprobacionService $comprobacion,
    ) {}

    /**
     * @return string el nuevo valor de verificacion_sat
     */
    public function verificar(Factura $factura): string
    {
        try {
            $disco = Storage::disk('local');
            $xml = $factura->xml_ruta !== null && $disco->exists($factura->xml_ruta) ? (string) $disco->get($factura->xml_ruta) : null;

            if ($xml === null) {
                throw new ConsultaSatException('No se encuentra el XML de la factura.');
            }

            $resultado = $this->consultor->consultar($xml);

            $factura->forceFill([
                'verificacion_sat' => $resultado->estado,
                'sat_es_cancelable' => $resultado->esCancelable,
                'sat_estatus_cancelacion' => $resultado->estatusCancelacion,
                'sat_detalle' => null,
            ]);
        } catch (ConsultaSatException $e) {
            $factura->forceFill([
                'verificacion_sat' => self::ERROR,
                'sat_detalle' => mb_substr($e->getMessage(), 0, 255),
            ]);
        }

        $factura->sat_consultado_at = now();
        $factura->save();

        activity('factura')->performedOn($factura)->withProperties(['sat' => $factura->verificacion_sat])->log('verificada en el SAT');

        $this->comprobacion->analizar($factura);

        return $factura->verificacion_sat;
    }

    /**
     * Facturas que conviene volver a consultar: las vigentes (pueden
     * cancelarse después), las que fallaron y las que nunca se consultaron.
     *
     * @return Collection<int, Factura>
     */
    public function pendientes(?int $anio = null, ?int $mes = null): Collection
    {
        return Factura::query()
            ->whereIn('verificacion_sat', [ResultadoSat::VIGENTE, self::ERROR, self::NO_VERIFICADO])
            ->when($anio !== null, fn ($q) => $q->where('anio', $anio))
            ->when($mes !== null, fn ($q) => $q->where('mes', $mes))
            ->orderBy('id')
            ->get();
    }
}
