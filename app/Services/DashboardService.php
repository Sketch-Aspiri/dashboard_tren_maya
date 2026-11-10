<?php

namespace App\Services;

use App\Enums\EmpleadoEstatus;
use App\Models\Empleado;
use App\Models\Seccion;
use App\Models\SeccionArchivo;
use App\Models\SeccionFila;
use App\Models\SolicitudInsumo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Indicadores del dashboard principal. Solo cifras respaldadas por datos
 * reales capturados en el sistema.
 */
final class DashboardService
{
    private const RECIENTES = 6;

    /** @var Collection<int, array<string, mixed>>|null */
    private ?Collection $asistenciaHoy = null;

    public function __construct(
        private readonly AsistenciaCapturaService $asistencia,
        private readonly SolicitudInsumoService $insumos,
    ) {}

    /**
     * Cada indicador lleva, además de su cifra, a dónde lleva al hacer clic
     * (`href`, opcional), un ícono y un `tono` cuando pide atención.
     *
     * @return list<array{label: string, value: string, hint: string, href: ?string, icono: string, tono: ?string}>
     */
    public function kpis(bool $incluirAsistencia): array
    {
        $kpis = [];

        if ($incluirAsistencia) {
            $resumen = $this->asistenciaHoy();
            $capturadas = $resumen->where('capturado', true)->count();
            $kpis[] = [
                'label' => __('Estaciones capturadas hoy'),
                'value' => $capturadas.'/'.$resumen->count(),
                'hint' => __('Control de Asistencia Diaria — Zona Oriente.'),
                'href' => route('asistencia.zona.index'),
                'icono' => 'asistencia',
                'tono' => $resumen->isNotEmpty() && $capturadas < $resumen->count() ? 'alerta' : null,
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
                'href' => route('agenda.personal.index'),
                'icono' => 'personal',
                'tono' => null,
            ],
            [
                'label' => __('Secciones con información'),
                'value' => "{$conInformacion}/{$totalSecciones}",
                'hint' => __('Secciones del menú con al menos un registro o documento.'),
                'href' => null,
                'icono' => 'secciones',
                'tono' => null,
            ],
            [
                'label' => __('Registros y documentos'),
                'value' => number_format(SeccionFila::query()->count()).' / '.number_format(SeccionArchivo::query()->count()),
                'hint' => __('Total de filas capturadas / documentos guardados.'),
                'href' => null,
                'icono' => 'documentos',
                'tono' => null,
            ],
        ];
    }

    /**
     * Asistencia de hoy por estación operativa (se consulta una sola vez
     * por petición; la usan el indicador y el panel de estaciones).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function asistenciaHoy(): Collection
    {
        return $this->asistenciaHoy ??= $this->asistencia->resumenDelDia(CarbonImmutable::today());
    }

    /**
     * Avance de las solicitudes de insumos del mes que se está pidiendo
     * (el mismo que abre el módulo por defecto).
     *
     * @return array{anio: int, mes: int, estaciones: int, sin_iniciar: int, borrador: int, por_revisar: int, observadas: int, aprobadas: int}
     */
    public function insumosDelMes(): array
    {
        $objetivo = $this->insumos->mesObjetivo();
        $estados = $this->insumos->tablero($objetivo['anio'], $objetivo['mes'])
            ->map(fn (array $fila) => $fila['solicitud']?->estado);

        $contar = fn (array $grupo) => $estados->filter(fn (?string $estado) => in_array($estado, $grupo, true))->count();

        return [
            'anio' => $objetivo['anio'],
            'mes' => $objetivo['mes'],
            'estaciones' => $estados->count(),
            'sin_iniciar' => $estados->filter(fn (?string $estado) => $estado === null)->count(),
            'borrador' => $contar([SolicitudInsumo::BORRADOR]),
            'por_revisar' => $contar([SolicitudInsumo::ENVIADA]),
            'observadas' => $contar([SolicitudInsumo::OBSERVADA, SolicitudInsumo::DEVUELTA]),
            'aprobadas' => $contar([SolicitudInsumo::APROBADA, SolicitudInsumo::ENVIADA_COORDINACION]),
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
