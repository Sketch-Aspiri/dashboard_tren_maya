<?php

namespace App\Services\Pendientes;

use App\Models\AguaRegistro;
use App\Models\HorarioPeriodo;
use App\Models\HorarioSolicitud;
use App\Models\PtarRegistro;
use App\Models\SolicitudInsumo;
use App\Models\SolicitudPapeleria;
use App\Models\User;
use App\Models\VacacionPeriodo;
use App\Models\VacacionPlan;
use App\Models\VacacionSolicitud;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Bandeja "Por revisar" del inicio de la Zona: todo lo que las estaciones ya enviaron y espera una decisión.
 * Los módulos mensuales salen por mes (el más antiguo primero) y cada fila abre donde se resuelve.
 */
final class PendientesZona
{
    /** @return Collection<int, array{clave: string, titulo: string, detalle: string|null, cantidad: int, href: string}> */
    public function para(User $usuario): Collection
    {
        $mensuales = [
            ['insumos', __('Solicitud de insumos'), SolicitudInsumo::class],
            ['papeleria', __('Solicitud de papelería'), SolicitudPapeleria::class],
            ['agua', __('Registro de agua'), AguaRegistro::class],
            ['ptar', __('Reporte de Estatus PTAR'), PtarRegistro::class],
        ];
        $filas = [];

        foreach ($mensuales as [$clave, $titulo, $modelo]) {
            if ($usuario->can('viewAny', $modelo)) {
                $filas = [...$filas, ...$this->porMes($clave, $titulo, $modelo)];
            }
        }

        if ($usuario->can('viewAny', HorarioPeriodo::class)) {
            // Un envío con varios cambios (lote) se resuelve junto: cuenta una vez.
            $filas[] = $this->fila('horarios', __('Cambios de horario'), $this->envios(HorarioSolicitud::query(), HorarioSolicitud::PENDIENTE), route('horarios.solicitudes.index'));
        }

        if ($usuario->can('viewAny', VacacionPeriodo::class)) {
            $filas[] = $this->fila('vacaciones', __('Cambios al rol de vacaciones'), $this->envios(VacacionSolicitud::query(), VacacionSolicitud::PENDIENTE), route('vacaciones.solicitudes.index'));
        }

        if ($usuario->can('gestionar', VacacionPlan::class)) {
            $anio = (int) config('vacaciones.anio_seleccion');
            $filas[] = $this->fila(
                'seleccion',
                __('Selección de vacaciones :anio', ['anio' => $anio]),
                VacacionPlan::query()->where('anio', $anio)->where('estado', VacacionPlan::ENVIADA)->count(),
                route('vacaciones.seleccion.revision', ['estado' => VacacionPlan::ENVIADA]),
            );
        }

        return collect($filas)->filter(fn (array $fila) => $fila['cantidad'] > 0)->values();
    }

    /**
     * @param  class-string  $modelo
     * @return list<array<string, mixed>>
     */
    private function porMes(string $clave, string $titulo, string $modelo): array
    {
        return $modelo::query()->where('estado', $modelo::ENVIADA)
            ->selectRaw('anio, mes, count(*) as total')->groupBy('anio', 'mes')->orderBy('anio')->orderBy('mes')->get()
            ->map(fn ($grupo) => $this->fila(
                $clave,
                $titulo,
                (int) $grupo->total,
                route($clave.'.index', ['anio' => (int) $grupo->anio, 'mes' => (int) $grupo->mes]),
                ucfirst(CarbonImmutable::create((int) $grupo->anio, (int) $grupo->mes, 1)->locale('es')->isoFormat('MMMM YYYY')),
            ))->all();
    }

    private function envios(Builder $consulta, string $pendiente): int
    {
        return $consulta->where('estado', $pendiente)->get(['id', 'lote'])
            ->unique(fn ($solicitud) => $solicitud->lote ?? 'id-'.$solicitud->id)->count();
    }

    /** @return array{clave: string, titulo: string, detalle: string|null, cantidad: int, href: string} */
    private function fila(string $clave, string $titulo, int $cantidad, string $href, ?string $detalle = null): array
    {
        return compact('clave', 'titulo', 'detalle', 'cantidad', 'href');
    }
}
