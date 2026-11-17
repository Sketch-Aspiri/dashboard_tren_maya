<?php

namespace App\Services\Horarios;

use App\Models\Estacion;
use App\Models\HorarioPeriodo;
use App\Models\HorarioSolicitud;
use App\Models\HorarioTurno;
use App\Models\User;
use App\Services\Ecosistema\AvisosEcosistema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Edición del horario desde la vista semanal. La estación NO modifica la tabla:
 * cada celda cambiada se vuelve una solicitud pendiente (todas con el mismo lote)
 * que la Zona autoriza. La Zona sí guarda directo.
 */
final class HorarioEdicionService
{
    public function __construct(
        private readonly HorarioSolicitudService $solicitudes,
        private readonly AvisosEcosistema $avisos,
    ) {}

    /**
     * Valor con el que se cargó la celda; sirve para detectar que otra persona la cambió.
     */
    public static function huella(HorarioTurno $turno): string
    {
        return $turno->jornada.'|'.($turno->comida ?? '');
    }

    /**
     * @param  array<int|string, array{jornada?: string|null, comida?: string|null}>  $cambios  turno_id => valores nuevos
     * @return array{lote: string, creadas: int}
     */
    public function proponer(User $user, array $cambios, string $motivo): array
    {
        $turnos = HorarioTurno::query()->with('periodo')->whereIn('id', array_keys($cambios))->get()->keyBy('id');

        if ($turnos->count() !== count($cambios)) {
            throw ValidationException::withMessages(['turnos' => __('Algún turno ya no existe. Recarga el horario.')]);
        }

        $lote = (string) Str::uuid();

        $creadas = DB::transaction(function () use ($user, $cambios, $turnos, $motivo, $lote) {
            $n = 0;

            foreach ($cambios as $id => $nuevo) {
                $turno = $turnos[(int) $id];

                if (! $user->can('solicitar', $turno)) {
                    throw new AuthorizationException;
                }

                $jornada = trim((string) ($nuevo['jornada'] ?? ''));
                $comida = trim((string) ($nuevo['comida'] ?? ''));

                if ($jornada === $turno->jornada && $comida === (string) $turno->comida) {
                    continue;
                }

                $this->solicitudes->crear($user, $turno, [
                    'tipo' => HorarioSolicitud::CAMBIO_TURNO,
                    'nueva_jornada' => $jornada,
                    'nueva_comida' => $comida !== (string) $turno->comida && $comida !== '' ? $comida : null,
                    'motivo' => $motivo,
                ], $lote, avisar: false);
                $n++;
            }

            if ($n === 0) {
                throw ValidationException::withMessages(['turnos' => __('No cambiaste ninguna celda.')]);
            }

            return $n;
        });

        $primera = HorarioSolicitud::query()->where('lote', $lote)->orderBy('id')->firstOrFail();
        $this->avisos->horarioSolicitudNueva($primera, $creadas);

        return ['lote' => $lote, 'creadas' => $creadas];
    }

    /**
     * La Zona edita directo (periodo vigente o borrador). Una fila que otra persona cambió
     * desde que se cargó el formulario no se sobrescribe y se reporta.
     *
     * @param  array<int|string, array{jornada?: string|null, comida?: string|null, antes?: string|null}>  $cambios
     * @return array{guardados: int, conflictos: list<string>}
     */
    public function guardarDirecto(User $user, array $cambios, ?string $nota): array
    {
        $conflictos = [];
        $porEstacion = [];
        $guardados = 0;

        DB::transaction(function () use ($user, $cambios, $nota, &$conflictos, &$porEstacion, &$guardados) {
            $turnos = HorarioTurno::query()->with('periodo')->whereIn('id', array_keys($cambios))->lockForUpdate()->get()->keyBy('id');

            foreach ($cambios as $id => $nuevo) {
                $turno = $turnos[(int) $id] ?? null;

                if ($turno === null) {
                    continue;
                }

                if (! $user->can('editar', $turno)) {
                    throw new AuthorizationException;
                }

                if (($nuevo['antes'] ?? null) !== self::huella($turno)) {
                    $conflictos[] = $turno->nombre.' ('.$turno->fecha->format('d/m/Y').')';

                    continue;
                }

                $jornada = trim((string) ($nuevo['jornada'] ?? ''));
                $comida = trim((string) ($nuevo['comida'] ?? ''));

                if ($jornada === $turno->jornada && $comida === (string) $turno->comida) {
                    continue;
                }

                $antes = self::huella($turno);

                if ($turno->periodo->estado === HorarioPeriodo::VIGENTE) {
                    $turno->recordarOficial();
                }

                $turno->fill([
                    'jornada' => $jornada,
                    'comida' => $comida !== '' ? $comida : null,
                    'editado_por' => $user->id,
                    'editado_at' => now(),
                    'nota' => $nota !== null && trim($nota) !== '' ? mb_substr(trim($nota), 0, 500) : null,
                ])->save();

                activity('horario')->performedOn($turno)->causedBy($user)
                    ->withProperties(['antes' => $antes, 'despues' => self::huella($turno)])->log('turno editado');

                $guardados++;

                if ($turno->periodo->estado === HorarioPeriodo::VIGENTE && $turno->estacion_id !== null) {
                    $porEstacion[$turno->estacion_id] = ($porEstacion[$turno->estacion_id] ?? 0) + 1;
                }
            }
        });

        foreach ($porEstacion as $estacionId => $cantidad) {
            $this->avisos->horarioEditado(Estacion::query()->findOrFail($estacionId), $cantidad);
        }

        return ['guardados' => $guardados, 'conflictos' => $conflictos];
    }
}
