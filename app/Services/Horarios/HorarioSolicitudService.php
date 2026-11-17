<?php

namespace App\Services\Horarios;

use App\Models\HorarioPeriodo;
use App\Models\HorarioSolicitud;
use App\Models\HorarioTurno;
use App\Models\User;
use App\Services\Ecosistema\AvisosEcosistema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cambios de horario: la estación pide (de uno en uno o en lote), Edificio
 * autoriza o rechaza y, al autorizar, se modifica la tabla (HorarioTurno). Un
 * cambio de fecha intercambia lo que la persona hace en los dos días.
 */
final class HorarioSolicitudService
{
    public function __construct(private readonly AvisosEcosistema $avisos) {}

    /**
     * @param  array{tipo: string, nueva_jornada?: string|null, nueva_comida?: string|null, nueva_fecha?: string|null, motivo: string}  $datos  Salida validada de CrearHorarioSolicitudRequest.
     * @param  string|null  $lote  Agrupa lo que la estación mandó en un mismo envío.
     * @param  bool  $avisar  Falso cuando quien llama manda un solo aviso por lote.
     */
    public function crear(User $user, HorarioTurno $turno, array $datos, ?string $lote = null, bool $avisar = true): HorarioSolicitud
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
        } elseif ($this->igual($datos['nueva_jornada'] ?? null, $turno->jornada) && ($this->vacioANull($datos['nueva_comida'] ?? null) === null || $this->igual($datos['nueva_comida'] ?? null, $turno->comida))) {
            throw ValidationException::withMessages(['nueva_jornada' => __('El cambio de :nombre del :fecha es igual a lo que ya tiene.', ['nombre' => $turno->nombre, 'fecha' => $turno->fecha->format('d/m/Y')])]);
        }

        if (HorarioSolicitud::query()->where('turno_id', $turno->id)->where('estado', HorarioSolicitud::PENDIENTE)->exists()) {
            throw ValidationException::withMessages(['turno' => __('El turno de :nombre del :fecha ya tiene una solicitud pendiente.', ['nombre' => $turno->nombre, 'fecha' => $turno->fecha->format('d/m/Y')])]);
        }

        $solicitud = HorarioSolicitud::query()->create([
            'periodo_id' => $turno->periodo_id,
            'lote' => $lote,
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

        if ($avisar) {
            $this->avisos->horarioSolicitudNueva($solicitud);
        }

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
        $actual = DB::transaction(fn () => $this->autorizarDentro($solicitud->id, $user, $comentario));

        $this->avisos->horarioSolicitudResuelta($actual);

        return $actual;
    }

    public function rechazar(HorarioSolicitud $solicitud, User $user, string $comentario): HorarioSolicitud
    {
        $actual = DB::transaction(fn () => $this->rechazarDentro($solicitud->id, $user, $comentario));

        $this->avisos->horarioSolicitudResuelta($actual);

        return $actual;
    }

    /**
     * Autoriza TODO el lote o nada: si un turno ya cambió, no se aplica ninguna y el
     * mensaje dice cuál, para resolver ese caso suelto.
     *
     * @return Collection<int, HorarioSolicitud>
     */
    public function autorizarLote(string $lote, User $user, ?string $comentario = null): Collection
    {
        $resueltas = DB::transaction(fn () => $this->pendientesDelLote($lote)->map(fn (HorarioSolicitud $s) => $this->autorizarDentro($s->id, $user, $comentario)));

        $this->avisos->horarioSolicitudResuelta($resueltas->first(), $resueltas->count());

        return $resueltas;
    }

    /**
     * @return Collection<int, HorarioSolicitud>
     */
    public function rechazarLote(string $lote, User $user, string $comentario): Collection
    {
        $resueltas = DB::transaction(fn () => $this->pendientesDelLote($lote)->map(fn (HorarioSolicitud $s) => $this->rechazarDentro($s->id, $user, $comentario)));

        $this->avisos->horarioSolicitudResuelta($resueltas->first(), $resueltas->count());

        return $resueltas;
    }

    /**
     * Pasa las solicitudes de la versión anterior del periodo a la nueva. Los
     * ajustes de la tabla ya se copiaron (copiarAjustes): aquí solo se vuelven a
     * ligar. Las pendientes se cancelan si el turno ya no existe y las autorizadas
     * quedan marcadas como no aplicadas si ya no cuadran con el oficial nuevo.
     */
    public function reaplicar(HorarioPeriodo $nuevo, HorarioPeriodo $anterior): void
    {
        $solicitudes = HorarioSolicitud::query()
            ->where('periodo_id', $anterior->id)
            ->whereIn('estado', [HorarioSolicitud::PENDIENTE, HorarioSolicitud::AUTORIZADA])
            ->orderBy('id')
            ->get();

        foreach ($solicitudes as $solicitud) {
            $a = $this->turnoDe($nuevo->id, $solicitud->no_empleado, $solicitud->fecha->toDateString());
            $b = $solicitud->tipo === HorarioSolicitud::CAMBIO_FECHA && $solicitud->nueva_fecha
                ? $this->turnoDe($nuevo->id, $solicitud->no_empleado, $solicitud->nueva_fecha->toDateString())
                : null;
            $cuadra = $a !== null && ($solicitud->tipo === HorarioSolicitud::CAMBIO_TURNO || $b !== null);

            $solicitud->periodo_id = $nuevo->id;
            $solicitud->turno_id = $a?->id;

            if ($solicitud->esPendiente()) {
                if (! $cuadra) {
                    $solicitud->estado = HorarioSolicitud::CANCELADA;
                    $solicitud->resuelta_at = now();
                    $solicitud->comentario_resolucion = __('El horario oficial se actualizó y este turno ya no existe.');
                } else {
                    $solicitud->jornada_origen = $a->jornada;
                    $solicitud->comida_origen = $a->comida;
                }
            } else {
                $solicitud->aplicada = $cuadra;
            }

            $solicitud->save();
        }
    }

    private function autorizarDentro(int $id, User $user, ?string $comentario): HorarioSolicitud
    {
        $actual = HorarioSolicitud::query()->lockForUpdate()->findOrFail($id);
        $this->exigirPendiente($actual);

        $a = $actual->turno_id ? HorarioTurno::query()->lockForUpdate()->with('periodo')->find($actual->turno_id) : null;

        if ($a === null || $a->periodo->estado !== HorarioPeriodo::VIGENTE) {
            throw ValidationException::withMessages(['solicitud' => __('El horario oficial cambió y el turno de :nombre del :fecha ya no existe: no se puede autorizar.', ['nombre' => $actual->nombre, 'fecha' => $actual->fecha->format('d/m/Y')])]);
        }

        if ($a->jornada !== $actual->jornada_origen || (string) $a->comida !== (string) $actual->comida_origen) {
            throw ValidationException::withMessages(['solicitud' => __('El turno de :nombre del :fecha cambió desde que se hizo la solicitud. Recházala para que la estación pida de nuevo.', ['nombre' => $actual->nombre, 'fecha' => $actual->fecha->format('d/m/Y')])]);
        }

        $b = $actual->tipo === HorarioSolicitud::CAMBIO_FECHA
            ? $this->turnoDe($a->periodo_id, $a->no_empleado, $actual->nueva_fecha->toDateString())
            : null;

        if ($actual->tipo === HorarioSolicitud::CAMBIO_FECHA && $b === null) {
            throw ValidationException::withMessages(['solicitud' => __('Ya no existe el turno de la fecha propuesta.')]);
        }

        $this->aplicar($actual, $a, $b, $user);

        $actual->update([
            'estado' => HorarioSolicitud::AUTORIZADA,
            'resuelta_por' => $user->id,
            'resuelta_at' => now(),
            'comentario_resolucion' => $this->vacioANull($comentario),
            'aplicada' => true,
        ]);

        return $actual;
    }

    private function rechazarDentro(int $id, User $user, string $comentario): HorarioSolicitud
    {
        $actual = HorarioSolicitud::query()->lockForUpdate()->findOrFail($id);
        $this->exigirPendiente($actual);

        $actual->update([
            'estado' => HorarioSolicitud::RECHAZADA,
            'resuelta_por' => $user->id,
            'resuelta_at' => now(),
            'comentario_resolucion' => trim($comentario),
        ]);

        return $actual;
    }

    /**
     * @return Collection<int, HorarioSolicitud>
     */
    private function pendientesDelLote(string $lote): Collection
    {
        $pendientes = HorarioSolicitud::query()->where('lote', $lote)->where('estado', HorarioSolicitud::PENDIENTE)->orderBy('id')->get();

        if ($pendientes->isEmpty()) {
            throw ValidationException::withMessages(['solicitud' => __('Este envío ya no tiene solicitudes pendientes.')]);
        }

        return $pendientes;
    }

    private function aplicar(HorarioSolicitud $solicitud, HorarioTurno $a, ?HorarioTurno $b, User $quien): void
    {
        $ahora = now();

        if ($solicitud->tipo === HorarioSolicitud::CAMBIO_TURNO) {
            $a->recordarOficial();
            $a->jornada = (string) $solicitud->nueva_jornada;

            if ($solicitud->nueva_comida !== null) {
                $a->comida = $solicitud->nueva_comida;
            }

            $a->fill(['editado_por' => $quien->id, 'editado_at' => $ahora, 'nota' => mb_substr($solicitud->motivo, 0, 500)])->save();

            return;
        }

        $a->recordarOficial();
        $b->recordarOficial();
        [$a->jornada, $b->jornada] = [$b->jornada, $a->jornada];
        [$a->comida, $b->comida] = [$b->comida, $a->comida];

        foreach ([$a, $b] as $turno) {
            $turno->fill(['editado_por' => $quien->id, 'editado_at' => $ahora, 'nota' => mb_substr($solicitud->motivo, 0, 500)])->save();
        }
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

    private function igual(mixed $a, mixed $b): bool
    {
        return mb_strtolower(trim((string) $a)) === mb_strtolower(trim((string) $b));
    }

    private function vacioANull(mixed $valor): ?string
    {
        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }
}
