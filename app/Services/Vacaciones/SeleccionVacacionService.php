<?php

namespace App\Services\Vacaciones;

use App\Models\Empleado;
use App\Models\User;
use App\Models\VacacionCatalogoPeriodo;
use App\Models\VacacionEstacionTope;
use App\Models\VacacionPlan;
use App\Models\VacacionSeleccion;
use App\Services\Ecosistema\AvisosEcosistema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Selección de vacaciones del personal. Reglas: solo periodos autorizados del catálogo del año, uno por
 * trimestre (el del periodo), sin traslape entre sí, sin pasar de los días que le corresponden (el envío
 * exige completarlos) y sin superar el tope de personas de su estación de vacaciones el mismo día
 * (cuentan los planes enviados y autorizados).
 */
final class SeleccionVacacionService
{
    public function __construct(private readonly AvisosEcosistema $avisos) {}

    public function planDe(Empleado $empleado, int $anio): VacacionPlan
    {
        if ($empleado->estacion_id === null) {
            throw ValidationException::withMessages(['plan' => __('Tu ficha no tiene estación asignada. Avisa a la Zona.')]);
        }

        return VacacionPlan::query()->firstOrCreate(
            ['anio' => $anio, 'empleado_id' => $empleado->id],
            ['estacion_id' => $empleado->estacion_id, 'estado' => VacacionPlan::BORRADOR, 'dias_correspondientes' => VacacionDiasCorrespondientes::para($empleado->fecha_ingreso, $anio)],
        );
    }

    public function tope(int $anio, int $estacionId): int
    {
        return (int) (VacacionEstacionTope::query()->where(['anio' => $anio, 'estacion_id' => $estacionId])->value('max_simultaneos') ?? VacacionEstacionTope::PREDETERMINADO);
    }

    /**
     * Guarda la elección como borrador (reemplaza la anterior).
     *
     * @param  array<int|string, int|string|null>  $porTrimestre  trimestre => id del periodo del catálogo (vacío = ninguno)
     */
    public function guardar(VacacionPlan $plan, array $porTrimestre): VacacionPlan
    {
        return DB::transaction(function () use ($plan, $porTrimestre) {
            $plan = VacacionPlan::query()->lockForUpdate()->findOrFail($plan->id);

            if (! $plan->editable()) {
                throw ValidationException::withMessages(['plan' => __('Tu selección ya fue enviada. Retírala para cambiarla.')]);
            }

            $elegidos = $this->resolver($plan, $porTrimestre);
            $this->validar($plan, $elegidos);

            $plan->selecciones()->delete();

            foreach ($elegidos as $periodo) {
                VacacionSeleccion::query()->create(['plan_id' => $plan->id, 'trimestre' => $periodo->trimestre, 'catalogo_periodo_id' => $periodo->id]);
            }

            if ($plan->estado === VacacionPlan::RECHAZADA) {
                $plan->update(['estado' => VacacionPlan::BORRADOR]);
            }

            return $plan->load('selecciones.periodo');
        });
    }

    public function enviar(VacacionPlan $plan): VacacionPlan
    {
        $plan = DB::transaction(function () use ($plan) {
            $plan = VacacionPlan::query()->with('selecciones.periodo')->lockForUpdate()->findOrFail($plan->id);

            if (! $plan->editable()) {
                throw ValidationException::withMessages(['plan' => __('Esta selección ya fue enviada.')]);
            }

            if ($plan->dias_correspondientes === 0) {
                throw ValidationException::withMessages(['plan' => __('No te corresponden vacaciones en este año.')]);
            }

            // Serializa los envíos de la misma estación para que el tope no se rebase con envíos simultáneos.
            VacacionPlan::query()->where(['anio' => $plan->anio, 'estacion_id' => $plan->estacion_id])->lockForUpdate()->get(['id']);

            $elegidos = $plan->selecciones->map->periodo->values()->all();
            $this->validar($plan, $elegidos);

            if ($plan->diasElegidos() !== $plan->dias_correspondientes) {
                throw ValidationException::withMessages(['plan' => __('Debes completar exactamente tus :n días (llevas :x).', ['n' => $plan->dias_correspondientes, 'x' => $plan->diasElegidos()])]);
            }

            $plan->update(['estado' => VacacionPlan::ENVIADA, 'enviada_at' => now(), 'comentario' => null, 'resuelta_por' => null, 'resuelta_at' => null]);

            return $plan;
        });

        $this->avisos->vacacionPlanEnviado($plan->loadMissing(['empleado:id,nombre_completo', 'estacion:id,nombre']));

        return $plan;
    }

    /** La persona retira su envío (solo si aún no está autorizado) para corregirlo. */
    public function retirar(VacacionPlan $plan): VacacionPlan
    {
        if ($plan->estado !== VacacionPlan::ENVIADA) {
            throw ValidationException::withMessages(['plan' => __('Solo se puede retirar una selección enviada que aún no se autoriza.')]);
        }

        $plan->update(['estado' => VacacionPlan::BORRADOR, 'enviada_at' => null]);

        return $plan;
    }

    public function autorizar(VacacionPlan $plan, User $usuario, ?string $comentario): VacacionPlan
    {
        return $this->resolverPlan($plan, $usuario, VacacionPlan::AUTORIZADA, $comentario);
    }

    public function rechazar(VacacionPlan $plan, User $usuario, string $comentario): VacacionPlan
    {
        return $this->resolverPlan($plan, $usuario, VacacionPlan::RECHAZADA, $comentario);
    }

    private function resolverPlan(VacacionPlan $plan, User $usuario, string $estado, ?string $comentario): VacacionPlan
    {
        $plan = DB::transaction(function () use ($plan, $usuario, $estado, $comentario) {
            $plan = VacacionPlan::query()->lockForUpdate()->findOrFail($plan->id);

            if ($plan->estado !== VacacionPlan::ENVIADA) {
                throw ValidationException::withMessages(['plan' => __('Esa selección ya fue resuelta o la persona la retiró.')]);
            }

            if ($usuario->empleado_id !== null && $usuario->empleado_id === $plan->empleado_id) {
                throw ValidationException::withMessages(['plan' => __('No puedes resolver tu propia selección; la revisa otra persona de la Zona.')]);
            }

            $plan->update(['estado' => $estado, 'resuelta_por' => $usuario->id, 'resuelta_at' => now(), 'comentario' => $comentario]);
            activity('vacaciones')->performedOn($plan)->causedBy($usuario)->withProperties(['estado' => $estado])->log('selección de vacaciones '.$estado);

            return $plan;
        });

        $this->avisos->vacacionPlanResuelto($plan);

        return $plan;
    }

    /**
     * Cuántas personas de la estación coinciden cada día (planes enviados y autorizados, sin contar $excluir).
     *
     * @return array<string, int> fecha Y-m-d => personas
     */
    public function ocupacion(int $anio, int $estacionId, ?int $excluir = null): array
    {
        $ocupados = VacacionSeleccion::query()->with('periodo:id,inicio,fin')
            ->whereHas('plan', fn ($q) => $q->where(['anio' => $anio, 'estacion_id' => $estacionId])->whereIn('estado', [VacacionPlan::ENVIADA, VacacionPlan::AUTORIZADA])
                ->when($excluir !== null, fn ($q) => $q->where('id', '!=', $excluir)))
            ->get();

        $dias = [];

        foreach ($ocupados as $s) {
            for ($d = $s->periodo->inicio->copy(); $d->lte($s->periodo->fin); $d->addDay()) {
                if (! $d->isWeekend()) {
                    $dias[$d->toDateString()] = ($dias[$d->toDateString()] ?? 0) + 1;
                }
            }
        }

        return $dias;
    }

    /** Máximo de coincidentes dentro del periodo (para mostrar cupo y validar). */
    public function pico(VacacionCatalogoPeriodo $periodo, array $ocupacion): int
    {
        $pico = 0;

        for ($d = $periodo->inicio->copy(); $d->lte($periodo->fin); $d->addDay()) {
            $pico = max($pico, $ocupacion[$d->toDateString()] ?? 0);
        }

        return $pico;
    }

    /**
     * @param  array<int|string, int|string|null>  $porTrimestre
     * @return list<VacacionCatalogoPeriodo>
     */
    private function resolver(VacacionPlan $plan, array $porTrimestre): array
    {
        $ids = array_values(array_filter(array_map(fn ($v) => $v === null || $v === '' ? null : (int) $v, $porTrimestre)));
        $periodos = VacacionCatalogoPeriodo::query()->where('anio', $plan->anio)->whereIn('id', $ids)->get()->keyBy('id');

        foreach ($ids as $id) {
            $p = $periodos->get($id);

            if ($p === null) {
                throw ValidationException::withMessages(['plan' => __('Uno de los periodos no existe en el catálogo :anio.', ['anio' => $plan->anio])]);
            }

            if (! $p->autorizado) {
                throw ValidationException::withMessages(['plan' => __('El periodo :p no está autorizado.', ['p' => $p->etiqueta()])]);
            }
        }

        foreach ($porTrimestre as $trimestre => $id) {
            $p = $id === null || $id === '' ? null : $periodos->get((int) $id);

            if ($p !== null && (int) $trimestre !== (int) $p->trimestre) {
                throw ValidationException::withMessages(['plan' => __('El periodo :p no es del trimestre :t.', ['p' => $p->etiqueta(), 't' => $trimestre])]);
            }
        }

        return $periodos->values()->all();
    }

    /** @param  list<VacacionCatalogoPeriodo>  $elegidos */
    private function validar(VacacionPlan $plan, array $elegidos): void
    {
        $trimestres = array_map(fn ($p) => $p->trimestre, $elegidos);

        if (count($trimestres) !== count(array_unique($trimestres))) {
            throw ValidationException::withMessages(['plan' => __('Solo puedes elegir un periodo por trimestre.')]);
        }

        if (array_sum(array_map(fn ($p) => $p->dias, $elegidos)) > $plan->dias_correspondientes) {
            throw ValidationException::withMessages(['plan' => __('Pasas de tus :n días de vacaciones.', ['n' => $plan->dias_correspondientes])]);
        }

        foreach ($elegidos as $a) {
            foreach ($elegidos as $b) {
                if ($a->id < $b->id && $a->inicio->lte($b->fin) && $b->inicio->lte($a->fin)) {
                    throw ValidationException::withMessages(['plan' => __('Los periodos :a y :b se traslapan.', ['a' => $a->etiqueta(), 'b' => $b->etiqueta()])]);
                }
            }
        }

        $tope = $this->tope($plan->anio, $plan->estacion_id);
        $ocupacion = $this->ocupacion($plan->anio, $plan->estacion_id, $plan->id);

        foreach ($elegidos as $p) {
            if ($this->pico($p, $ocupacion) >= $tope) {
                throw ValidationException::withMessages(['plan' => __('El periodo :p ya está lleno en tu estación (máximo :n a la vez).', ['p' => $p->etiqueta(), 'n' => $tope])]);
            }
        }
    }
}
