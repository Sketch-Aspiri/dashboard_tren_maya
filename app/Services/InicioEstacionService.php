<?php

namespace App\Services;

use App\Models\MenuGrupo;
use App\Models\Seccion;
use App\Models\User;
use App\Services\Secciones\VisibilidadSecciones;
use App\Support\Areas;
use App\Support\Roles;
use Illuminate\Support\Collection;

/**
 * Datos de la pantalla de inicio de una cuenta Estación: sus secciones
 * aprobadas (con cuántas filas y documentos suyos tiene cada una). Lo que le
 * toca capturar lo arma PendientesEstacion.
 */
final class InicioEstacionService
{
    public function __construct(private readonly VisibilidadSecciones $visibilidad) {}

    /**
     * @return array{secciones: Collection<int, array{grupo: MenuGrupo, secciones: Collection<int, Seccion>}>, area: string|null, accesos: list<array{etiqueta: string, ruta: string}>}
     */
    public function para(User $usuario): array
    {
        $ids = array_keys($this->visibilidad->idsVisiblesPara($usuario) ?? []);

        $secciones = Seccion::query()
            ->whereIn('id', $ids)
            ->with('grupo')
            ->withCount([
                'filas' => fn ($q) => $q->visiblePara($usuario),
                'archivos' => fn ($q) => $q->visiblePara($usuario),
            ])
            ->orderBy('orden')
            ->orderBy('nombre')
            ->get();

        $porGrupo = $secciones
            ->filter(fn (Seccion $s) => $s->grupo !== null && $usuario->puedeUsarArea(Areas::deGrupo($s->grupo->slug)))
            ->groupBy('menu_grupo_id')
            ->map(fn (Collection $delGrupo) => ['grupo' => $delGrupo->first()->grupo, 'secciones' => $delGrupo->values()])
            ->sortBy(fn (array $g) => [$g['grupo']->orden, $g['grupo']->id])
            ->values();

        return [
            'secciones' => $porGrupo,
            'area' => $this->areaDe($usuario),
            'accesos' => $this->accesosDeArea($usuario),
        ];
    }

    /** Nombre del área de la cuenta, solo con áreas estrictas (si no, el inicio queda como siempre). */
    private function areaDe(User $usuario): ?string
    {
        return config('equipos.estrictas') ? collect($usuario->areas())->map(fn (string $clave) => Areas::nombres()[$clave] ?? null)->filter()->implode(' · ') ?: null : null;
    }

    /**
     * Atajos a los módulos de su área (solo con áreas estrictas).
     *
     * @return list<array{etiqueta: string, ruta: string}>
     */
    private function accesosDeArea(User $usuario): array
    {
        if (! config('equipos.estrictas')) {
            return [];
        }

        // Los módulos fijos (config/navegacion.php) de los grupos del menú que pertenecen a su equipo.
        return collect(config('navegacion.grupos'))
            ->filter(fn (array $grupo) => in_array(Areas::deGrupo($grupo['slug']), $usuario->areas(), true))
            ->flatMap(fn (array $grupo) => $grupo['items'])
            ->filter(fn (array $item) => in_array(Roles::ESTACION, $item['roles'], true))
            ->map(fn (array $item) => ['etiqueta' => __($item['label']), 'ruta' => route($item['ruta'])])
            ->values()
            ->all();
    }
}
