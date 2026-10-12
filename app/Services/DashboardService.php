<?php

namespace App\Services;

use App\Enums\EmpleadoEstatus;
use App\Models\Empleado;
use App\Models\Seccion;
use App\Models\SeccionArchivo;
use App\Models\SeccionFila;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Indicadores del dashboard principal. Solo cifras respaldadas por datos
 * reales capturados en el sistema.
 */
final class DashboardService
{
    private const RECIENTES = 6;

    public function __construct(private readonly AsistenciaCapturaService $asistencia) {}

    /**
     * @return list<array{label: string, value: string, hint: string}>
     */
    public function kpis(bool $incluirAsistencia): array
    {
        $kpis = [];

        if ($incluirAsistencia) {
            $resumen = $this->asistencia->resumenDelDia(CarbonImmutable::today());
            $kpis[] = [
                'label' => __('Estaciones capturadas hoy'),
                'value' => $resumen->where('capturado', true)->count().'/'.$resumen->count(),
                'hint' => __('Control de Asistencia Diaria — Zona Oriente.'),
            ];
        }

        $totalSecciones = Seccion::query()->count();
        $conInformacion = Seccion::query()
            ->where(fn ($consulta) => $consulta->whereHas('filas')->orWhereHas('archivos'))
            ->count();

        return [
            ...$kpis,
            [
                'label' => __('Personal activo'),
                'value' => number_format(Empleado::query()->where('estatus', EmpleadoEstatus::Activo->value)->count()),
                'hint' => __('RR.HH. — Personal con estatus activo.'),
            ],
            [
                'label' => __('Secciones con información'),
                'value' => "{$conInformacion}/{$totalSecciones}",
                'hint' => __('Secciones del menú con al menos un registro o documento.'),
            ],
            [
                'label' => __('Registros y documentos'),
                'value' => number_format(SeccionFila::query()->count()).' / '.number_format(SeccionArchivo::query()->count()),
                'hint' => __('Total de filas capturadas / documentos guardados.'),
            ],
        ];
    }

    /**
     * Secciones con la actividad más reciente (filas o documentos).
     *
     * @return Collection<int, array{seccion: Seccion, actualizada: CarbonImmutable}>
     */
    public function seccionesRecientes(): Collection
    {
        return Seccion::query()
            ->with('grupo:id,nombre')
            ->withMax('filas', 'updated_at')
            ->withMax('archivos', 'created_at')
            ->get()
            ->map(function (Seccion $seccion): ?array {
                $fechas = array_filter([$seccion->filas_max_updated_at, $seccion->archivos_max_created_at]);

                return $fechas === [] ? null : ['seccion' => $seccion, 'actualizada' => CarbonImmutable::parse(max($fechas))];
            })
            ->filter()
            ->sortByDesc(fn (array $item) => $item['actualizada']->timestamp)
            ->take(self::RECIENTES)
            ->values();
    }
}
