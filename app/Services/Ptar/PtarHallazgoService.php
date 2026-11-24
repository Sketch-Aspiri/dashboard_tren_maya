<?php

namespace App\Services\Ptar;

use App\Models\PtarHallazgo;
use App\Models\PtarPlanta;
use App\Models\User;
use App\Services\Ecosistema\ActividadesEcosistema;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Hallazgos de las PTAR (problemáticas y propuestas) con seguimiento: abierto -> en proceso -> cerrado, fecha
 * compromiso y, a petición de la Zona, una actividad en Tickets.
 */
final class PtarHallazgoService
{
    public function __construct(private readonly ActividadesEcosistema $actividades) {}

    /**
     * @param  array<string, mixed>  $datos  Salida validada de GuardarHallazgoPtarRequest.
     */
    public function crear(array $datos, User $user): PtarHallazgo
    {
        $datos['estado'] ??= PtarHallazgo::ABIERTO;
        $datos['fecha_cierre'] = $datos['estado'] === PtarHallazgo::CERRADO ? ($datos['fecha_cierre'] ?? now('America/Cancun')->toDateString()) : null;

        return PtarHallazgo::query()->create($datos + [
            'fecha_detectado' => now('America/Cancun')->toDateString(),
            'capturo_id' => $user->id,
        ]);
    }

    /**
     * Actualiza el hallazgo. Al cerrarlo guarda la fecha de cierre (hoy si no se indica); al reabrirlo la borra.
     *
     * @param  array<string, mixed>  $datos
     */
    public function actualizar(PtarHallazgo $hallazgo, array $datos, User $user): PtarHallazgo
    {
        $estado = $datos['estado'] ?? $hallazgo->estado;
        $datos['fecha_cierre'] = $estado === PtarHallazgo::CERRADO
            ? ($datos['fecha_cierre'] ?? $hallazgo->fecha_cierre?->toDateString() ?? now('America/Cancun')->toDateString())
            : null;

        $hallazgo->update($datos);
        activity('ptar')->performedOn($hallazgo)->causedBy($user)->log('hallazgo actualizado: '.$estado);

        return $hallazgo->refresh();
    }

    /** Pide a Tickets la actividad del hallazgo (una sola vez; el `ref` evita duplicados aunque se reintente). */
    public function enviarATickets(PtarHallazgo $hallazgo, User $user): PtarHallazgo
    {
        if (! $hallazgo->estaAbierto()) {
            throw ValidationException::withMessages(['tickets' => __('El hallazgo ya está cerrado.')]);
        }

        if ($hallazgo->vinculosTickets()->exists()) {
            throw ValidationException::withMessages(['tickets' => __('Este hallazgo ya tiene actividad en Tickets.')]);
        }

        // Si Tickets rechazó la actividad (p. ej. sin equipo configurado) no llega folio: se permite reintentar pasada una hora.
        if ($hallazgo->tickets_enviado_at !== null && $hallazgo->tickets_enviado_at->gt(now()->subHour())) {
            throw ValidationException::withMessages(['tickets' => __('Este hallazgo se mandó a Tickets hace poco; espera el folio.')]);
        }

        if (! $this->actividades->disponible('ptar_hallazgo')) {
            throw ValidationException::withMessages(['tickets' => __('Tickets no está disponible para este evento.')]);
        }

        $this->actividades->ptarHallazgo($hallazgo);
        $hallazgo->update(['tickets_enviado_at' => now()]);
        activity('ptar')->performedOn($hallazgo)->causedBy($user)->log('enviado a Tickets');

        return $hallazgo;
    }

    /**
     * Hallazgos filtrados, los abiertos primero y por prioridad; con la planta y su estación.
     *
     * @param  array{estado?: string|null, planta?: int|string|null, tipo?: string|null}  $filtros
     * @param  list<int>|null  $estaciones  Limitar a estas estaciones (la estación solo ve la suya).
     * @return Collection<int, PtarHallazgo>
     */
    public function listar(array $filtros, ?array $estaciones = null): Collection
    {
        $consulta = PtarHallazgo::query()->with(['planta.estacion', 'vinculosTickets']);

        if ($estaciones !== null) {
            $consulta->whereHas('planta', fn ($q) => $q->whereIn('estacion_id', $estaciones));
        }

        $estado = $filtros['estado'] ?? 'activos';
        if ($estado === 'activos') {
            $consulta->where('estado', '!=', PtarHallazgo::CERRADO);
        } elseif ($estado !== 'todos') {
            $consulta->where('estado', $estado);
        }

        if (! empty($filtros['planta'])) {
            $consulta->where('planta_id', (int) $filtros['planta']);
        }

        if (! empty($filtros['tipo'])) {
            $consulta->where('tipo', $filtros['tipo']);
        }

        return $consulta->orderByRaw("case estado when 'cerrado' then 1 else 0 end")
            ->orderByRaw("case prioridad when 'alta' then 0 when 'media' then 1 else 2 end")
            ->orderBy('planta_id')->orderBy('id')->get();
    }

    /** @return Collection<int, PtarPlanta> */
    public function plantas(?array $estaciones = null): Collection
    {
        PtarPlanta::sincronizar();

        return PtarPlanta::query()->where('activa', true)->with('estacion')
            ->when($estaciones !== null, fn ($q) => $q->whereIn('estacion_id', $estaciones))
            ->get()->sortBy(fn (PtarPlanta $p) => $p->numero ?? PHP_INT_MAX)->values();
    }
}
