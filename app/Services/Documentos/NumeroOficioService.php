<?php

namespace App\Services\Documentos;

use App\Enums\TipoDocumentoAsistencia;
use App\Models\DocumentoAsistencia;
use App\Models\Estacion;
use Carbon\CarbonInterface;

/**
 * Suggests the next "Tjta. No." for an oficio. Only a suggestion: the
 * person generating the document can edit it, because the real numbering
 * is shared with oficios this system never sees.
 */
final class NumeroOficioService
{
    /**
     * T.M.M./RR.HH./23PMO/00340/2026 — clave (when known), 5-digit
     * consecutive, year. With a clave the count continues from that station's
     * oficios of the year. Without one the folio is not station-specific, so
     * it continues from ALL clave-less station folios of the year — otherwise
     * two stations would be suggested the very same (unique) number.
     */
    public function sugerirParaEstacion(Estacion $estacion, CarbonInterface $fecha): string
    {
        $clave = (config('asistencia_documentos.estacion.claves') ?? [])[$estacion->nombre] ?? null;
        $siguiente = $this->ultimoConsecutivoDeEstacion($estacion, $fecha, $clave) + 1;

        return sprintf('T.M.M./RR.HH./%s%05d/%d', $clave !== null ? $clave.'/' : '', $siguiente, $fecha->year);
    }

    /**
     * TM/UAI/CGGIF/DGTZO/1735 — the zone's consecutive is shared with every
     * other oficio of the Dirección, which this system never sees, so it can
     * only continue from the last zone oficio it generated. With none yet,
     * only the fixed prefix is suggested and the number is typed by hand.
     */
    public function sugerirParaZona(): string
    {
        $prefijo = (string) config('asistencia_documentos.zona.folio_prefijo');

        $ultimo = DocumentoAsistencia::query()
            ->where('tipo', TipoDocumentoAsistencia::Zona->value)
            ->orderByDesc('id')
            ->value('numero_oficio');

        if ($ultimo !== null && preg_match('#/(\d+)$#', $ultimo, $coincidencia) === 1) {
            return $prefijo.((int) $coincidencia[1] + 1);
        }

        return $prefijo;
    }

    /**
     * Highest consecutive already used. A hand-edited folio that does not
     * follow the pattern counts as 0 rather than being guessed at.
     */
    private function ultimoConsecutivoDeEstacion(Estacion $estacion, CarbonInterface $fecha, ?string $clave): int
    {
        $consulta = DocumentoAsistencia::query()
            ->where('tipo', TipoDocumentoAsistencia::Estacion->value)
            ->whereYear('fecha', $fecha->year);

        if ($clave !== null) {
            $consulta->where('estacion_id', $estacion->id);
        }

        $patron = $clave !== null ? '#/(\d+)/\d{4}$#' : '#^T\.M\.M\./RR\.HH\./(\d+)/\d{4}$#';

        return (int) $consulta->pluck('numero_oficio')
            ->map(fn (string $numero) => preg_match($patron, $numero, $coincidencia) === 1 ? (int) $coincidencia[1] : 0)
            ->max();
    }
}
