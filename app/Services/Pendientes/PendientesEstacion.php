<?php

namespace App\Services\Pendientes;

use App\Models\AguaRegistro;
use App\Models\Estacion;
use App\Models\PtarPlanta;
use App\Models\PtarRegistro;
use App\Models\SolicitudInsumo;
use App\Models\SolicitudPapeleria;
use App\Models\User;
use App\Services\Afluencia\AfluenciaService;
use App\Services\Agua\AguaService;
use App\Services\AsistenciaCapturaService;
use App\Services\Ptar\PtarService;
use App\Services\SolicitudInsumoService;
use App\Support\Areas;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * "Mis pendientes" del inicio de una cuenta Estación: qué le toca capturar de cada módulo de su área y en qué
 * estado va. Solo consulta (no crea registros) y siempre filtra por la estación de la cuenta.
 *
 * Estados: alerta (la Zona lo devolvió), pendiente (falta capturar o enviar), revision (lo tiene la Zona) y listo.
 */
final class PendientesEstacion
{
    private const ORDEN = ['alerta' => 0, 'pendiente' => 1, 'revision' => 2, 'listo' => 3];

    public function __construct(
        private readonly AsistenciaCapturaService $asistencia,
        private readonly AfluenciaService $afluencia,
        private readonly SolicitudInsumoService $insumos,
        private readonly AguaService $agua,
        private readonly PtarService $ptar,
    ) {}

    /** @return Collection<int, array{clave: string, titulo: string, detalle: string, estado: string, accion: string, href: string}> */
    public function para(User $usuario): Collection
    {
        $estacion = $usuario->estacion;

        if ($estacion === null) {
            return collect();
        }

        $filas = [];

        if ($usuario->puedeUsarArea(Areas::deGrupo('rrhh'))) {
            $filas[] = $this->asistenciaDeHoy($estacion);
        }

        if ($estacion->is_operativa && $usuario->puedeUsarArea(Areas::deGrupo('recursos-financieros'))) {
            $filas[] = $this->flujoDePasajeros($estacion);
        }

        if ($usuario->puedeUsarArea(Areas::deGrupo('recursos-materiales'))) {
            $filas = [...$filas, ...$this->recursosMateriales($estacion)];
        }

        // sortBy es estable: dentro de cada estado se respeta el orden de arriba.
        return collect($filas)->filter()->sortBy(fn (array $fila) => self::ORDEN[$fila['estado']])->values();
    }

    /** @return array<string, string>|null */
    private function asistenciaDeHoy(Estacion $estacion): ?array
    {
        $resumen = $this->asistencia->resumenDelDia(CarbonImmutable::today())
            ->first(fn (array $fila) => $fila['estacion']->id === $estacion->id);

        if ($resumen === null) {
            return null;
        }

        $avance = '('.$resumen['total_capturado'].'/'.$resumen['total_roster'].')';

        return $this->fila(
            'asistencia',
            __('Captura de asistencia de hoy'),
            ($resumen['capturado'] ? __('Capturada') : __('Pendiente')).' '.$avance,
            $resumen['capturado'] ? 'listo' : 'pendiente',
            $resumen['capturado'] ? __('Ver captura') : __('Capturar'),
            route('asistencia.captura.index'),
        );
    }

    /** @return array<string, string> */
    private function flujoDePasajeros(Estacion $estacion): array
    {
        $falta = $this->afluencia->faltantesHastaAyer($estacion);

        return $this->fila(
            'afluencia',
            __('Flujo de pasajeros'),
            $falta['faltan'] === 0
                ? __('Al corriente hasta ayer')
                : trans_choice('Falta :n día hasta ayer|Faltan :n días hasta ayer', $falta['faltan'], ['n' => $falta['faltan']]),
            $falta['faltan'] === 0 ? 'listo' : 'pendiente',
            $falta['faltan'] === 0 ? __('Ver captura') : __('Capturar'),
            route('afluencia.estacion', ['estacion' => $estacion->id, 'anio' => $falta['anio'], 'mes' => $falta['mes']]),
        );
    }

    /**
     * Insumos, agua y PTAR del mes en curso de cada uno, más lo que la Zona haya devuelto de otros meses.
     * Papelería no es mensual obligatoria: solo aparece cuando está devuelta.
     *
     * @return list<array<string, string>>
     */
    private function recursosMateriales(Estacion $estacion): array
    {
        $modulos = [
            ['insumos', __('Solicitud de insumos'), SolicitudInsumo::class, 'insumos', $this->insumos->mesObjetivo()],
            ['agua', __('Registro de agua'), AguaRegistro::class, 'agua', $this->agua->mesActual()],
        ];

        if (PtarPlanta::query()->where('estacion_id', $estacion->id)->where('activa', true)->exists()) {
            $modulos[] = ['ptar', __('Reporte de Estatus PTAR'), PtarRegistro::class, 'ptar', $this->ptar->mesActual()];
        }

        $modulos[] = ['papeleria', __('Solicitud de papelería'), SolicitudPapeleria::class, 'papeleria', null];

        $filas = [];

        foreach ($modulos as [$clave, $titulo, $modelo, $ruta, $periodo]) {
            $registro = $periodo === null ? null : $modelo::query()->where('estacion_id', $estacion->id)->where($periodo)->first();

            if ($periodo !== null) {
                $filas[] = $this->delMes($clave, $titulo, $registro?->estado, $periodo, route($ruta.'.index', $periodo));
            }

            $devueltos = $modelo::query()->where('estacion_id', $estacion->id)->where('estado', $modelo::OBSERVADA)
                ->when($registro !== null, fn ($consulta) => $consulta->whereKeyNot($registro->getKey()))
                ->orderBy('anio')->orderBy('mes')->get();

            foreach ($devueltos as $devuelto) {
                $filas[] = $this->delMes($clave, $titulo, $devuelto->estado, $devuelto->only(['anio', 'mes']), route($ruta.'.show', $devuelto));
            }
        }

        return $filas;
    }

    /**
     * @param  array{anio: int, mes: int}  $periodo
     * @return array<string, string>
     */
    private function delMes(string $clave, string $titulo, ?string $estado, array $periodo, string $href): array
    {
        [$situacion, $texto, $accion] = match ($estado) {
            null => ['pendiente', __('Sin iniciar'), __('Capturar')],
            'borrador' => ['pendiente', __('En captura, falta enviar'), __('Continuar')],
            'observada' => ['alerta', __('La Zona la devolvió'), __('Corregir')],
            'enviada' => ['revision', __('En revisión de la Zona'), __('Ver')],
            default => ['listo', __('Aprobada'), __('Ver')],
        };
        $mes = CarbonImmutable::create($periodo['anio'], $periodo['mes'], 1)->locale('es')->isoFormat('MMMM YYYY');

        return $this->fila($clave, $titulo, ucfirst($mes).' · '.$texto, $situacion, $accion, $href);
    }

    /** @return array{clave: string, titulo: string, detalle: string, estado: string, accion: string, href: string} */
    private function fila(string $clave, string $titulo, string $detalle, string $estado, string $accion, string $href): array
    {
        return compact('clave', 'titulo', 'detalle', 'estado', 'accion', 'href');
    }
}
