<?php

namespace App\Services\Horarios;

use App\Models\HorarioPeriodo;
use App\Models\HorarioSolicitud;
use App\Models\HorarioTurno;
use App\Models\User;
use App\Services\Ecosistema\AvisosEcosistema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cambios de horario: la estación pide, Edificio autoriza o rechaza y, al
 * autorizar, se modifica la tabla (HorarioTurno). Un cambio de fecha
 * intercambia lo que la persona hace en los dos días.
 */
final class HorarioSolicitudService
{
    public function __construct(private readonly AvisosEcosistema $avisos) {}

    /**
     * @param  array{tipo: string, nueva_jornada?: string|null, nueva_comida?: string|null, nueva_fecha?: string|null, motivo: string}  $datos  Salida validada de CrearHorarioSolicitudRequest.
     */
    public function crear(User $user, HorarioTurno $turno, array $datos): HorarioSolicitud
    {
        $turno->loadMissing('periodo');

        if ($turno->periodo->estado !== HorarioPeriodo::VIGENTE || $turno->estacion_id === null) {
            throw ValidationException::withMessages(['turno' => __('Este turno ya no admite solicitudes.')]);
        }

        $tipo = $datos['tipo'];
        $nuevaFecha = null;

        if ($tipo === HorarioSolicitud::CAMBIO_FECHA) {
            $nuevaFecha = (string) ($datos['nueva_fecha'] ?? '');

            if ($nuevaFecha === $turno->fecha->toDateString() || $this->turnoDe($turno->periodo_id, $turno->no_empleado, $nuevaFecha) === null) {
                throw ValidationException::withMessages(['nueva_fecha' => __('Esa persona no tiene turno en la fecha elegida de este horario.')]);
            }
        } elseif (mb_strtolower(trim((string) ($datos['nueva_jornada'] ?? ''))) === mb_strtolower(trim($turno->jornada))) {
            throw ValidationException::withMessages(['nueva_jornada' => __('La jornada nueva es igual a la actual.')]);
        }

        if (HorarioSolicitud::query()->where('turno_id', $turno->id)->where('estado', HorarioSolicitud::PENDIENTE)->exists()) {
            throw ValidationException::withMessages(['turno' => __('Este turno ya tiene una solicitud pendiente.')]);
        }

        $solicitud = HorarioSolicitud::query()->create([
            'periodo_id' => $turno->periodo_id,
            'estacion_id' => $turno->estacion_id,
            'turno_id' => $turno->id,
            'solicitante_id' => $user->id,
            'no_empleado' => $turno->no_empleado,
            'nombre' => $turno->nombre,
            'fecha' => $turno->fecha->toDateString(),
            'tipo' => $tipo,
            'jornada_origen' => $turno->jornada,
            'comida_origen' => $turno->comida,
            'nueva_jornada' => $tipo === HorarioSolicitud::CAMBIO_TURNO ? trim((string) $datos['nueva_jornada']) : null,
            'nueva_comida' => $tipo === HorarioSolicitud::CAMBIO_TURNO ? $this->vacioANull($datos['nueva_comida'] ?? null) : null,
            'nueva_fecha' => $nuevaFecha,
            'motivo' => trim($datos['motivo']),
            'estado' => HorarioSolicitud::PENDIENTE,
        ]);

        $this->avisos->horarioSolicitudNueva($solicitud);

        return $solicitud;
    }

    public function cancelar(HorarioSolicitud $solicitud, User $user): HorarioSolicitud
    {
        return DB::transaction(function () use ($solicitud, $user) {
            $actual = HorarioSolicitud::query()->lockForUpdate()->findOrFail($solicitud->id);
            $this->exigirPendiente($actual);

            $actual->update(['estado' => HorarioSolicitud::CANCELADA, 'resuelta_por' => $user->id, 'resuelta_at' => now()]);

            return $actual;
        });
    }

    public function autorizar(HorarioSolicitud $solicitud, User $user, ?string $comentario = null): HorarioSolicitud
    {
        $actual = DB::transaction(function () use ($solicitud, $user, $comentario) {
            $actual = HorarioSolicitud::query()->lockForUpdate()->findOrFail($solicitud->id);
            $this->exigirPendiente($actual);

            $a = $actual->turno_id ? HorarioTurno::query()->lockForUpdate()->with('periodo')->find($actual->turno_id) : null;

            if ($a === null || $a->periodo->estado !== HorarioPeriodo::VIGENTE) {
                throw ValidationException::withMessages(['solicitud' => __('El horario oficial cambió y este turno ya no existe: la solicitud no se puede autorizar.')]);
            }

            if ($a->jornada !== $actual->jornada_origen || (string) $a->comida !== (string) $actual->comida_origen) {
                throw ValidationException::withMessages(['solicitud' => __('El turno cambió desde que se hizo la solicitud. Recházala para que la estación pida de nuevo.')]);
            }

            $b = $actual->tipo === HorarioSolicitud::CAMBIO_FECHA
                ? $this->turnoDe($a->periodo_id, $a->no_empleado, $actual->nueva_fecha->toDateString())
                : null;

            if ($actual->tipo === HorarioSolicitud::CAMBIO_FECHA && $b === null) {
                throw ValidationException::withMessages(['solicitud' => __('Ya no existe el turno de la fecha propuesta.')]);
            }

            $this->aplicar($actual, $a, $b);

            $actual->update([
                'estado' => HorarioSolicitud::AUTORIZADA,
                'resuelta_por' => $user->id,
                'resuelta_at' => now(),
                'comentario_resolucion' => $this->vacioANull($comentario),
                'aplicada' => true,
            ]);

            return $actual;
        });

        $this->avisos->horarioSolicitudResuelta($actual);

        return $actual;
    }

    public function rechazar(HorarioSolicitud $solicitud, User $user, string $comentario): HorarioSolicitud
    {
        $actual = DB::transaction(function () use ($solicitud, $user, $comentario) {
            $actual = HorarioSolicitud::query()->lockForUpdate()->findOrFail($solicitud->id);
            $this->exigirPendiente($actual);

            $actual->update([
                'estado' => HorarioSolicitud::RECHAZADA,
                'resuelta_por' => $user->id,
                'resuelta_at' => now(),
                'comentario_resolucion' => trim($comentario),
            ]);

            return $actual;
        });

        $this->avisos->horarioSolicitudResuelta($actual);

        return $actual;
    }

    /**
     * Pasa las solicitudes de la versión anterior del periodo a la nueva: las
     * autorizadas se vuelven a aplicar encima del oficial nuevo; las pendientes
     * se vuelven a ligar (o se cancelan si el turno ya no existe).
     *
     * @return int Autorizadas que ya no se pudieron aplicar (persona o fecha inexistente).
     */
    public function reaplicar(HorarioPeriodo $nuevo, HorarioPeriodo $anterior): int
    {
        $sinAplicar = 0;

        $solicitudes = HorarioSolicitud::query()
            ->where('periodo_id', $anterior->id)
            ->whereIn('estado', [HorarioSolicitud::PENDIENTE, HorarioSolicitud::AUTORIZADA])
            ->orderBy('resuelta_at')->orderBy('id')
            ->get();

        foreach ($solicitudes as $solicitud) {
            $a = $this->turnoDe($nuevo->id, $solicitud->no_empleado, $solicitud->fecha->toDateString());
            $b = $solicitud->tipo === HorarioSolicitud::CAMBIO_FECHA && $solicitud->nueva_fecha
                ? $this->turnoDe($nuevo->id, $solicitud->no_empleado, $solicitud->nueva_fecha->toDateString())
                : null;

            $solicitud->periodo_id = $nuevo->id;
            $solicitud->turno_id = $a?->id;

            if ($solicitud->esPendiente()) {
                if ($a === null || ($solicitud->tipo === HorarioSolicitud::CAMBIO_FECHA && $b === null)) {
                    $solicitud->estado = HorarioSolicitud::CANCELADA;
                    $solicitud->resuelta_at = now();
                    $solicitud->comentario_resolucion = __('El horario oficial se actualizó y este turno ya no existe.');
                } else {
                    $solicitud->jornada_origen = $a->jornada;
                    $solicitud->comida_origen = $a->comida;
                }
            } elseif ($a !== null && ($solicitud->tipo === HorarioSolicitud::CAMBIO_TURNO || $b !== null)) {
                $this->aplicar($solicitud, $a, $b);
                $solicitud->aplicada = true;
            } else {
                $solicitud->aplicada = false;
                $sinAplicar++;
            }

            $solicitud->save();
        }

        return $sinAplicar;
    }

    private function aplicar(HorarioSolicitud $solicitud, HorarioTurno $a, ?HorarioTurno $b): void
    {
        if ($solicitud->tipo === HorarioSolicitud::CAMBIO_TURNO) {
            $a->recordarOficial();
            $a->jornada = (string) $solicitud->nueva_jornada;

            if ($solicitud->nueva_comida !== null) {
                $a->comida = $solicitud->nueva_comida;
            }

            $a->save();

            return;
        }

        $a->recordarOficial();
        $b->recordarOficial();
        [$a->jornada, $b->jornada] = [$b->jornada, $a->jornada];
        [$a->comida, $b->comida] = [$b->comida, $a->comida];
        $a->save();
        $b->save();
    }

    private function turnoDe(int $periodoId, string $noEmpleado, string $fecha): ?HorarioTurno
    {
        return HorarioTurno::query()
            ->where('periodo_id', $periodoId)
            ->where('no_empleado', $noEmpleado)
            ->whereDate('fecha', $fecha)
            ->first();
    }

    private function exigirPendiente(HorarioSolicitud $solicitud): void
    {
        if (! $solicitud->esPendiente()) {
            throw ValidationException::withMessages(['solicitud' => __('Esta solicitud ya fue resuelta.')]);
        }
    }

    private function vacioANull(mixed $valor): ?string
    {
        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }
}
