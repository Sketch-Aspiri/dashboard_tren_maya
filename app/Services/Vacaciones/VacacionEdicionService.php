<?php

namespace App\Services\Vacaciones;

use App\Models\User;
use App\Models\VacacionPeriodo;
use App\Models\VacacionPersona;
use App\Models\VacacionSolicitud;
use App\Models\VacacionTramo;
use App\Services\Ecosistema\AvisosEcosistema;
use App\Support\AlcanceEstacion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Cambios al rol de vacaciones. La ZONA edita directo; la ESTACIÓN solo propone (un lote por envío,
 * motivo obligatorio) y la Zona autoriza o rechaza el lote completo. Reglas de cada persona: fechas
 * dentro del año, inicio y fin juntos, sin traslape entre trimestres y sin pasar de los días otorgados.
 */
final class VacacionEdicionService
{
    public function __construct(private readonly AvisosEcosistema $avisos) {}

    /**
     * @param  array<int|string, array{inicio?: string|null, fin?: string|null}>  $cambios  tramo_id => fechas
     * @return array{modo: 'directo'|'solicitud', cantidad: int}
     */
    public function cambiar(User $user, VacacionPeriodo $periodo, array $cambios, ?string $motivo): array
    {
        if ($periodo->estado !== VacacionPeriodo::VIGENTE) {
            throw ValidationException::withMessages(['tramos' => __('Solo se modifica la versión vigente del rol.')]);
        }

        $esZona = AlcanceEstacion::esTotal($user);
        $estacionId = AlcanceEstacion::estacionId($user);

        if (! $esZona && $estacionId === null) {
            abort(403);
        }

        $tramos = VacacionTramo::query()->with('persona.tramos')->where('periodo_id', $periodo->id)
            ->whereIn('id', array_map('intval', array_keys($cambios)))->get()->keyBy('id');

        $propuestos = [];

        foreach ($cambios as $id => $fechas) {
            $tramo = $tramos->get((int) $id);

            if ($tramo === null || (! $esZona && $tramo->estacion_id !== $estacionId)) {
                abort(403);
            }

            [$inicio, $fin] = $this->normalizar($fechas['inicio'] ?? null, $fechas['fin'] ?? null);

            if ($inicio === $tramo->inicio?->toDateString() && $fin === $tramo->fin?->toDateString()) {
                continue;
            }

            $propuestos[$tramo->id] = ['tramo' => $tramo, 'inicio' => $inicio, 'fin' => $fin];
        }

        if ($propuestos === []) {
            throw ValidationException::withMessages(['tramos' => __('No cambiaste ninguna fecha.')]);
        }

        $this->validarPersonas($periodo, $propuestos);

        if ($esZona) {
            DB::transaction(fn () => $this->aplicarDirecto($user, $propuestos, $motivo));
            $this->avisarEdicion($propuestos);

            return ['modo' => 'directo', 'cantidad' => count($propuestos)];
        }

        $this->crearSolicitudes($user, $periodo, $estacionId, $propuestos, $motivo);

        return ['modo' => 'solicitud', 'cantidad' => count($propuestos)];
    }

    public function autorizarLote(string $lote, User $user, ?string $comentario = null): Collection
    {
        $resueltas = DB::transaction(function () use ($lote, $user, $comentario) {
            $pendientes = $this->pendientes($lote);
            $propuestos = [];

            foreach ($pendientes as $s) {
                $tramo = VacacionTramo::query()->with('persona.tramos')->lockForUpdate()->find($s->tramo_id);

                if ($tramo === null || $tramo->inicio?->toDateString() !== $s->inicio_origen?->toDateString() || $tramo->fin?->toDateString() !== $s->fin_origen?->toDateString()) {
                    throw ValidationException::withMessages(['lote' => __(':nombre (trimestre :t) ya cambió desde que se pidió. Rechaza el envío y que lo pidan de nuevo.', ['nombre' => $s->nombre, 't' => $s->trimestre])]);
                }

                $propuestos[$tramo->id] = ['tramo' => $tramo, 'inicio' => $s->nuevo_inicio?->toDateString(), 'fin' => $s->nuevo_fin?->toDateString()];
            }

            $this->validarPersonas($pendientes->first()->periodo, $propuestos);
            $this->aplicarDirecto($user, $propuestos, null, false);

            return $pendientes->map(function (VacacionSolicitud $s) use ($user, $comentario) {
                $s->update(['estado' => VacacionSolicitud::AUTORIZADA, 'resuelta_por' => $user->id, 'resuelta_at' => now(), 'comentario_resolucion' => $comentario, 'aplicada' => true]);

                return $s;
            });
        });

        $this->avisos->vacacionSolicitudResuelta($resueltas->first(), $resueltas->count());

        return $resueltas;
    }

    public function rechazarLote(string $lote, User $user, string $comentario): Collection
    {
        $resueltas = DB::transaction(fn () => $this->pendientes($lote)->map(function (VacacionSolicitud $s) use ($user, $comentario) {
            $s->update(['estado' => VacacionSolicitud::RECHAZADA, 'resuelta_por' => $user->id, 'resuelta_at' => now(), 'comentario_resolucion' => $comentario]);

            return $s;
        }));

        $this->avisos->vacacionSolicitudResuelta($resueltas->first(), $resueltas->count());

        return $resueltas;
    }

    public function cancelarLote(string $lote, User $user): int
    {
        return DB::transaction(function () use ($lote, $user) {
            $pendientes = $this->pendientes($lote);

            if ($pendientes->first()->estacion_id !== AlcanceEstacion::estacionId($user)) {
                abort(403);
            }

            $pendientes->each->update(['estado' => VacacionSolicitud::CANCELADA, 'resuelta_por' => $user->id, 'resuelta_at' => now()]);

            return $pendientes->count();
        });
    }

    /**
     * @return Collection<int, VacacionSolicitud>
     */
    private function pendientes(string $lote): Collection
    {
        $pendientes = VacacionSolicitud::query()->with(['periodo', 'estacion:id,nombre'])->where('lote', $lote)
            ->where('estado', VacacionSolicitud::PENDIENTE)->lockForUpdate()->get();

        if ($pendientes->isEmpty()) {
            throw ValidationException::withMessages(['lote' => __('Ese envío ya fue resuelto o cancelado.')]);
        }

        return $pendientes;
    }

    /**
     * @param  array<int, array{tramo: VacacionTramo, inicio: string|null, fin: string|null}>  $propuestos
     */
    private function aplicarDirecto(User $user, array $propuestos, ?string $nota, bool $registrar = true): void
    {
        foreach ($propuestos as $p) {
            $tramo = $p['tramo'];
            $tramo->recordarOficial();
            $tramo->fill([
                'inicio' => $p['inicio'], 'fin' => $p['fin'], 'dias' => $this->dias($p['inicio'], $p['fin']),
                'editado_por' => $user->id, 'editado_at' => now(), 'nota' => $nota !== null ? Str::limit($nota, 500, '') : $tramo->nota,
            ])->save();

            if ($registrar) {
                activity('vacaciones')->performedOn($tramo)->causedBy($user)->withProperties(['inicio' => $p['inicio'], 'fin' => $p['fin']])->log('vacaciones editadas por la Zona');
            }
        }
    }

    /**
     * @param  array<int, array{tramo: VacacionTramo, inicio: string|null, fin: string|null}>  $propuestos
     */
    private function crearSolicitudes(User $user, VacacionPeriodo $periodo, int $estacionId, array $propuestos, ?string $motivo): void
    {
        if ($motivo === null || trim($motivo) === '') {
            throw ValidationException::withMessages(['motivo' => __('Escribe el motivo del cambio.')]);
        }

        $ids = array_keys($propuestos);

        if (VacacionSolicitud::query()->whereIn('tramo_id', $ids)->where('estado', VacacionSolicitud::PENDIENTE)->exists()) {
            throw ValidationException::withMessages(['tramos' => __('Uno de esos trimestres ya tiene un cambio pendiente de autorizar.')]);
        }

        $lote = (string) Str::uuid();
        $ultima = null;

        DB::transaction(function () use ($user, $periodo, $estacionId, $propuestos, $motivo, $lote, &$ultima) {
            foreach ($propuestos as $p) {
                $t = $p['tramo'];
                $ultima = VacacionSolicitud::query()->create([
                    'periodo_id' => $periodo->id, 'lote' => $lote, 'estacion_id' => $estacionId, 'tramo_id' => $t->id,
                    'solicitante_id' => $user->id, 'no_empleado' => $t->persona->no_empleado, 'nombre' => $t->persona->nombre,
                    'trimestre' => $t->trimestre, 'inicio_origen' => $t->inicio, 'fin_origen' => $t->fin, 'dias_origen' => $t->dias,
                    'nuevo_inicio' => $p['inicio'], 'nuevo_fin' => $p['fin'], 'nuevos_dias' => $this->dias($p['inicio'], $p['fin']),
                    'motivo' => Str::limit(trim($motivo), 1000, ''), 'estado' => VacacionSolicitud::PENDIENTE,
                ]);
            }
        });

        $this->avisos->vacacionSolicitudNueva($ultima->load('estacion:id,nombre'), count($propuestos));
    }

    /**
     * @param  array<int, array{tramo: VacacionTramo, inicio: string|null, fin: string|null}>  $propuestos
     */
    private function avisarEdicion(array $propuestos): void
    {
        collect($propuestos)->groupBy(fn (array $p) => $p['tramo']->estacion_id)->each(function ($grupo, $estacionId) {
            $estacion = $grupo->first()['tramo']->estacion;

            if ($estacion !== null) {
                $this->avisos->vacacionesEditadas($estacion, $grupo->count());
            }
        });
    }

    /**
     * Valida el estado final de cada persona tocada por los cambios.
     *
     * @param  array<int, array{tramo: VacacionTramo, inicio: string|null, fin: string|null}>  $propuestos
     */
    private function validarPersonas(VacacionPeriodo $periodo, array $propuestos): void
    {
        $personas = collect($propuestos)->map(fn (array $p) => $p['tramo']->persona)->unique('id');

        foreach ($personas as $persona) {
            $rangos = [];

            foreach ($persona->tramos as $t) {
                $nuevo = $propuestos[$t->id] ?? null;
                $inicio = $nuevo ? $nuevo['inicio'] : $t->inicio?->toDateString();
                $fin = $nuevo ? $nuevo['fin'] : $t->fin?->toDateString();

                if ($inicio === null) {
                    continue;
                }

                if ($fin < $inicio) {
                    $this->error($persona, __('la fecha de término es anterior a la de inicio'));
                }

                if (substr($inicio, 0, 4) !== (string) $periodo->anio || substr($fin, 0, 4) !== (string) $periodo->anio) {
                    $this->error($persona, __('las fechas deben estar dentro de :anio', ['anio' => $periodo->anio]));
                }

                $rangos[] = [$inicio, $fin, $this->dias($inicio, $fin)];
            }

            usort($rangos, fn ($a, $b) => strcmp($a[0], $b[0]));

            for ($i = 1; $i < count($rangos); $i++) {
                if ($rangos[$i][0] <= $rangos[$i - 1][1]) {
                    $this->error($persona, __('hay trimestres que se traslapan'));
                }
            }

            // El rol real ya trae personas con más días que los otorgados (rangos de lunes a lunes): solo se
            // rechaza un cambio que haga crecer el exceso.
            $antes = $persona->tramos->sum('dias');

            if (array_sum(array_column($rangos, 2)) > max($persona->dias_otorgados, $antes)) {
                $this->error($persona, __('supera sus :n días otorgados', ['n' => $persona->dias_otorgados]));
            }
        }
    }

    private function error(VacacionPersona $persona, string $detalle): never
    {
        throw ValidationException::withMessages(['tramos' => $persona->nombre.': '.$detalle.'.']);
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function normalizar(?string $inicio, ?string $fin): array
    {
        $inicio = $inicio === null || trim($inicio) === '' ? null : trim($inicio);
        $fin = $fin === null || trim($fin) === '' ? null : trim($fin);

        if (($inicio === null) !== ($fin === null)) {
            throw ValidationException::withMessages(['tramos' => __('Cada trimestre lleva fecha de inicio y de término, o ninguna.')]);
        }

        return [$inicio, $fin];
    }

    private function dias(?string $inicio, ?string $fin): int
    {
        return $inicio === null ? 0 : VacacionCalculo::diasHabiles(CarbonImmutable::parse($inicio), CarbonImmutable::parse($fin));
    }
}
