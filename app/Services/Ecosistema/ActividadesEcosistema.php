<?php

namespace App\Services\Ecosistema;

use App\Jobs\EnviarActividadTickets;
use App\Models\Estacion;
use App\Models\Factura;
use App\Models\SolicitudInsumo;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Eventos del Dashboard que se vuelven actividades en Tickets: una solicitud de insumos observada por Zona o
 * devuelta por Coordinación, una factura cancelada en el SAT y una estación sin asistencia capturada a la hora
 * límite. Cada evento encola un trabajo (nunca rompe la acción que lo origina). El EQUIPO destino lo decide
 * Tickets; aquí solo se manda lo mínimo: estación, mes, folio, acción y el enlace al registro (jamás montos,
 * RFC ni datos personales). Cada hecho lleva un `ref` único: reenviarlo no duplica la actividad.
 */
final class ActividadesEcosistema
{
    public function __construct(private readonly PuenteCliente $puente) {}

    /** La Zona devolvió (observó) la solicitud → actividad para la estación, con sus observaciones. */
    public function solicitudObservada(SolicitudInsumo $solicitud): void
    {
        $nombre = $this->estacion($solicitud);
        $observaciones = $this->limpio((string) $solicitud->comentario_zona, 400);

        $this->enviar(
            'insumos_observada',
            'dashboard:solicitud:'.$solicitud->id.':observada:'.$this->marca($solicitud->revisada_at),
            $solicitud->estacion->codigo ?? null,
            'Solicitud de insumos observada: '.$nombre,
            'La Zona devolvió la solicitud de '.$this->mes($solicitud).' de '.$nombre.' con observaciones.'
                .($observaciones !== '' ? ' Observaciones: '.$observaciones : '').' Corrígela y vuelve a enviarla.',
            route('insumos.estacion', $solicitud->estacion_id, false),
            $solicitud,
        );
    }

    /** Coordinación devolvió la solicitud con cambios → actividad para la estación. */
    public function solicitudDevuelta(SolicitudInsumo $solicitud): void
    {
        $nombre = $this->estacion($solicitud);

        $this->enviar(
            'insumos_devuelta',
            'dashboard:solicitud:'.$solicitud->id.':devuelta:'.$this->marca($solicitud->devuelta_at),
            $solicitud->estacion->codigo ?? null,
            'Solicitud de insumos devuelta por Coordinación: '.$nombre,
            'Coordinación devolvió la solicitud de '.$this->mes($solicitud).' de '.$nombre.' con ajustes en las partidas (cantidades, precios, partidas agregadas o quitadas). Revísalos en el Dashboard.',
            route('insumos.estacion', $solicitud->estacion_id, false),
            $solicitud,
        );
    }

    /** El SAT reporta cancelada una factura → actividad para Recursos materiales. */
    public function facturaCancelada(Factura $factura): void
    {
        $referencia = $this->limpio($factura->referencia() !== '' ? $factura->referencia() : '#'.$factura->id, 40);

        $this->enviar(
            'factura_cancelada',
            'dashboard:factura:'.$factura->id.':cancelada_sat',
            null,
            'Factura cancelada en el SAT: '.$referencia,
            'El SAT reporta como cancelada la factura '.$referencia.'. No debe comprobarse ni pagarse; revisa la comprobación del fondo rotatorio.',
            route('insumos.facturas.show', $factura->id, false),
            $factura,
        );
    }

    /** La estación no capturó la asistencia a la hora límite → actividad para la estación. */
    public function asistenciaPendiente(Estacion $estacion, CarbonInterface $fecha): void
    {
        $nombre = $this->limpio((string) $estacion->nombre, 40);

        $this->enviar(
            'asistencia_pendiente',
            'dashboard:asistencia:'.$estacion->id.':'.$fecha->toDateString(),
            $estacion->codigo,
            'Asistencia sin capturar: '.$nombre,
            $nombre.' no capturó la asistencia del '.$fecha->format('d/m/Y').' a la hora límite. Captúrala en el Dashboard.',
            route('asistencia.captura.index', [], false),
            $estacion,
        );
    }

    /**
     * Los eventos que se mandan (ECOSISTEMA_ACT_EVENTOS). Quitar uno lo apaga en el Dashboard; el equipo que lo
     * recibe se configura en Tickets.
     */
    public function habilitado(string $evento): bool
    {
        return in_array($evento, (array) config('ecosistema.actividades.eventos', []), true);
    }

    private function enviar(string $evento, string $ref, ?string $codigoEstacion, string $titulo, string $descripcion, string $ruta, Model $origen): void
    {
        if (! $this->puente->activo() || ! $this->habilitado($evento)) {
            return;
        }

        try {
            EnviarActividadTickets::dispatch(
                $evento,
                Str::limit($ref, 120, ''),
                $codigoEstacion !== null && preg_match('/^[A-Za-z0-9_-]{1,20}$/D', $codigoEstacion) === 1 ? $codigoEstacion : null,
                $this->limpio($titulo, 120),
                $this->limpio($descripcion, 1000),
                rtrim((string) config('ecosistema.urls.dashboard'), '/').$ruta,
                $origen->getMorphClass(),
                (int) $origen->getKey(),
            );
        } catch (Throwable $e) {
            // Una actividad nunca debe impedir la acción que la originó.
            Log::warning('No se pudo encolar la actividad para Tickets.', ['evento' => $evento, 'error' => $e::class]);
        }
    }

    private function estacion(SolicitudInsumo $solicitud): string
    {
        $solicitud->loadMissing('estacion:id,nombre,codigo');

        return $this->limpio((string) ($solicitud->estacion->nombre ?? 'Una estación'), 40);
    }

    private function mes(SolicitudInsumo $solicitud): string
    {
        return Carbon::create((int) $solicitud->anio, (int) $solicitud->mes, 1)->locale('es')->translatedFormat('F Y');
    }

    private function marca(?CarbonInterface $momento): int
    {
        return ($momento ?? now())->getTimestamp();
    }

    /**
     * Texto plano en una sola línea y recortado: sin caracteres de control.
     */
    private function limpio(string $texto, int $max): string
    {
        return Str::limit(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $texto)), $max, '');
    }
}
