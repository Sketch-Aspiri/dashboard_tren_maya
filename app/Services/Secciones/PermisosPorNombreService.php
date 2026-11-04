<?php

namespace App\Services\Secciones;

use App\Enums\NivelAccesoEstacion;
use App\Models\Estacion;
use App\Models\Seccion;
use App\Models\SeccionTabla;
use Illuminate\Support\Facades\DB;

/**
 * Da permiso de Editar a cada estación sobre las subsecciones y hojas cuyo
 * nombre la menciona (p. ej. "PUERTO MORELOS" dentro de "Hojas de datos del
 * personal"). El permiso de una subsección se hereda a lo que cuelga de ella.
 * Solo suma: nunca baja ni quita un permiso existente. Idempotente.
 */
final class PermisosPorNombreService
{
    public function __construct(private readonly VisibilidadSecciones $visibilidad) {}

    /**
     * @return array{secciones: list<array{id: int, estacion: string, ruta: string}>, hojas: list<array{id: int, estacion: string, ruta: string}>}
     */
    public function aplicar(bool $simular = false, ?int $usuarioId = null): array
    {
        $estaciones = Estacion::query()->get(["id", "nombre"]);
        // Los nombres más largos primero: "Tulum Aeropuerto" antes que "Tulum".
        $alias = $estaciones
            ->flatMap(fn (Estacion $e) => collect([$e->nombre, str_replace("Zonal", "Zona", $e->nombre)])->unique()->map(fn (string $n) => [$this->normalizar($n), $e]))
            ->sortByDesc(fn (array $par) => strlen($par[0]))
            ->values();

        $encontrar = function (string $nombre) use ($alias): ?Estacion {
            $nombre = $this->normalizar($nombre);

            foreach ($alias as [$clave, $estacion]) {
                if ($clave !== '' && str_contains($nombre, $clave)) {
                    return $estacion;
                }
            }

            return null;
        };

        $secciones = Seccion::query()->get(['id', 'parent_id', 'nombre'])->keyBy('id');
        $ruta = function (Seccion $s) use ($secciones): string {
            $partes = [$s->nombre];

            for ($p = $secciones->get($s->parent_id); $p !== null && count($partes) < 12; $p = $secciones->get($p->parent_id)) {
                array_unshift($partes, $p->nombre);
            }

            return implode(' > ', $partes);
        };

        // Estaciones que ya cubre algún ancestro (o la propia sección) por su nombre.
        $coincide = $secciones->map(fn (Seccion $s) => $encontrar($s->nombre));
        $cubre = function (int $seccionId, int $estacionId) use ($secciones, $coincide): bool {
            for ($s = $secciones->get($seccionId); $s !== null; $s = $secciones->get($s->parent_id)) {
                if ($coincide->get($s->id)?->id === $estacionId) {
                    return true;
                }
            }

            return false;
        };

        $resultado = ['secciones' => [], 'hojas' => []];
        $actualesSec = DB::table('seccion_estacion')->get()->mapWithKeys(fn ($r) => [$r->seccion_id.':'.$r->estacion_id => $r->nivel]);
        $actualesTab = DB::table('seccion_tabla_estacion')->get()->mapWithKeys(fn ($r) => [$r->seccion_tabla_id.':'.$r->estacion_id => $r->nivel]);
        $editar = NivelAccesoEstacion::Editar->value;

        DB::transaction(function () use ($secciones, $coincide, $cubre, $encontrar, $ruta, $actualesSec, $actualesTab, $editar, $simular, $usuarioId, &$resultado): void {
            foreach ($secciones as $seccion) {
                $estacion = $coincide->get($seccion->id);

                // Solo la sección más alta que nombra a la estación; lo de abajo lo hereda.
                if ($estacion === null || ($seccion->parent_id !== null && $cubre($seccion->parent_id, $estacion->id))) {
                    continue;
                }

                if (($actualesSec[$seccion->id.':'.$estacion->id] ?? null) === $editar) {
                    continue;
                }

                $resultado['secciones'][] = ['id' => $seccion->id, 'estacion' => $estacion->nombre, 'ruta' => $ruta($seccion)];

                if (! $simular) {
                    DB::table('seccion_estacion')->updateOrInsert(
                        ['seccion_id' => $seccion->id, 'estacion_id' => $estacion->id],
                        ['nivel' => $editar, 'aprobado_por' => $usuarioId, 'updated_at' => now(), 'created_at' => $actualesSec->has($seccion->id.':'.$estacion->id) ? DB::raw('created_at') : now()],
                    );
                }
            }

            foreach (SeccionTabla::query()->get(['id', 'seccion_id', 'nombre']) as $tabla) {
                $estacion = $encontrar($tabla->nombre);

                if ($estacion === null || $cubre((int) $tabla->seccion_id, $estacion->id)) {
                    continue;
                }

                if (($actualesSec[$tabla->seccion_id.':'.$estacion->id] ?? null) === $editar || ($actualesTab[$tabla->id.':'.$estacion->id] ?? null) === $editar) {
                    continue;
                }

                $resultado['hojas'][] = ['id' => $tabla->id, 'estacion' => $estacion->nombre, 'ruta' => $ruta($secciones->get($tabla->seccion_id)).' > [hoja] '.$tabla->nombre];

                if (! $simular) {
                    DB::table('seccion_tabla_estacion')->updateOrInsert(
                        ['seccion_tabla_id' => $tabla->id, 'estacion_id' => $estacion->id],
                        ['nivel' => $editar, 'aprobado_por' => $usuarioId, 'updated_at' => now(), 'created_at' => $actualesTab->has($tabla->id.':'.$estacion->id) ? DB::raw('created_at') : now()],
                    );
                }
            }
        });

        if (! $simular) {
            $this->visibilidad->olvidar();
        }

        return $resultado;
    }

    private function normalizar(string $texto): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto) ?: $texto;

        return trim((string) preg_replace('/\s+/', ' ', strtolower((string) preg_replace('/[^A-Za-z0-9 ]/', ' ', $ascii))));
    }
}
