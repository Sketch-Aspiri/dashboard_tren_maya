<?php

namespace App\Services;

use App\Models\CatalogoPapeleria;
use App\Models\Estacion;
use App\Models\SolicitudPapeleria;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lógica de "Recursos materiales" -> Solicitud de papelería: captura de la
 * estación, revisión de la Zona y tablero de envíos. Sin precios ni límites:
 * solo artículo, unidad, cantidad y comentarios, como el formato oficial.
 */
final class SolicitudPapeleriaService
{
    /**
     * Mes que se está solicitando: desde el día 15 se pide el mes siguiente
     * (misma regla que la solicitud de insumos).
     *
     * @return array{anio: int, mes: int}
     */
    public function mesObjetivo(?Carbon $hoy = null): array
    {
        $hoy ??= now();
        $objetivo = $hoy->day >= 15 ? $hoy->copy()->startOfMonth()->addMonth() : $hoy->copy()->startOfMonth();

        return ['anio' => $objetivo->year, 'mes' => $objetivo->month];
    }

    public function obtenerOCrear(Estacion $estacion, int $anio, int $mes, User $user): SolicitudPapeleria
    {
        $solicitud = SolicitudPapeleria::query()->firstOrCreate(
            ['estacion_id' => $estacion->id, 'anio' => $anio, 'mes' => $mes],
            ['estado' => SolicitudPapeleria::BORRADOR],
        );

        if ($solicitud->wasRecentlyCreated) {
            activity('solicitud_papeleria')->performedOn($solicitud)->causedBy($user)->log('creada');
        }

        return $solicitud;
    }

    /**
     * Reemplaza las partidas capturadas por la estación.
     *
     * @param  array<string, mixed>  $datos  Salida validada de GuardarSolicitudPapeleriaRequest.
     */
    public function guardar(SolicitudPapeleria $solicitud, array $datos, User $user): SolicitudPapeleria
    {
        return DB::transaction(function () use ($solicitud, $datos, $user) {
            $solicitud->partidas()->delete();

            foreach (array_values($datos['partidas']) as $indice => $fila) {
                $catalogo = CatalogoPapeleria::query()->where('descripcion', $fila['articulo'])->first();

                $solicitud->partidas()->create([
                    'numero' => $indice + 1,
                    'catalogo_papeleria_id' => $catalogo?->id,
                    'articulo' => $fila['articulo'],
                    'unidad' => $fila['unidad'],
                    'cantidad' => $fila['cantidad'],
                    'comentarios' => $fila['comentarios'] ?? null,
                ]);
            }

            activity('solicitud_papeleria')->performedOn($solicitud)->causedBy($user)
                ->withProperties(['partidas' => count($datos['partidas'])])->log('partidas guardadas');

            return $solicitud->refresh();
        });
    }

    public function enviar(SolicitudPapeleria $solicitud, User $user): SolicitudPapeleria
    {
        if ($solicitud->partidas()->doesntExist()) {
            throw ValidationException::withMessages([
                'partidas' => __('Captura al menos un artículo antes de enviar.'),
            ]);
        }

        $solicitud->update(['estado' => SolicitudPapeleria::ENVIADA, 'enviada_at' => now()]);

        activity('solicitud_papeleria')->performedOn($solicitud)->causedBy($user)->log('enviada');

        return $solicitud;
    }

    /**
     * Decisión de la Zona: devolver (con comentario) o aprobar.
     *
     * @param  array<string, mixed>  $datos  Salida validada de RevisarSolicitudPapeleriaRequest.
     */
    public function revisar(SolicitudPapeleria $solicitud, array $datos, User $user): SolicitudPapeleria
    {
        $aprobar = $datos['accion'] === 'aprobar';

        $solicitud->update([
            'estado' => $aprobar ? SolicitudPapeleria::APROBADA : SolicitudPapeleria::OBSERVADA,
            'comentario_zona' => $datos['comentario_zona'] ?? null,
            'revisada_por' => $user->id,
            'revisada_at' => now(),
        ]);

        activity('solicitud_papeleria')->performedOn($solicitud)->causedBy($user)
            ->withProperties(['accion' => $datos['accion']])->log('revisada: '.$datos['accion']);

        return $solicitud->refresh();
    }

    /**
     * Una fila por estación (en su orden) con la solicitud del mes, si existe.
     *
     * @return Collection<int, array{estacion: Estacion, solicitud: SolicitudPapeleria|null}>
     */
    public function tablero(int $anio, int $mes): Collection
    {
        $solicitudes = SolicitudPapeleria::query()->withCount('partidas')
            ->where('anio', $anio)->where('mes', $mes)->get()->keyBy('estacion_id');

        return Estacion::query()->orderBy('orden')->get()
            ->map(fn (Estacion $estacion) => ['estacion' => $estacion, 'solicitud' => $solicitudes->get($estacion->id)]);
    }

    /**
     * Partidas aprobadas del mes, en el orden de las estaciones, para el Excel.
     *
     * @return Collection<int, array{estacion: string, articulo: string, unidad: string, cantidad: float, comentarios: string|null}>
     */
    public function filasAprobadas(int $anio, int $mes): Collection
    {
        $solicitudes = SolicitudPapeleria::query()->with('partidas')
            ->where('anio', $anio)->where('mes', $mes)
            ->where('estado', SolicitudPapeleria::APROBADA)->get()->keyBy('estacion_id');

        return Estacion::query()->orderBy('orden')->get()->flatMap(
            fn (Estacion $estacion) => ($solicitudes->get($estacion->id)?->partidas ?? collect())->map(fn ($p) => [
                'estacion' => mb_strtoupper($estacion->nombre),
                'articulo' => $p->articulo,
                'unidad' => $p->unidad,
                'cantidad' => (float) $p->cantidad,
                'comentarios' => $p->comentarios,
            ])
        )->values();
    }
}
