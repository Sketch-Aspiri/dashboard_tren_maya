<?php

namespace App\Support;

use App\Models\MenuGrupo;
use App\Models\Seccion;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Arma los grupos del menú para un usuario: los grupos y secciones visibles
 * de la base (administrables desde "Administrar menú") más los módulos
 * fijos de config/navegacion.php que su rol puede ver, con URL y estado
 * activo ya resueltos. Un grupo sin enlaces visibles se omite.
 */
class MenuNavegacion
{
    /** Subsecciones que se listan por nivel en la rama abierta antes de resumir el resto. */
    private const SUBSECCIONES_VISIBLES = 5;

    /**
     * Cada item trae 'nivel' (0 = primer nivel), 'oculto' (subsección de una rama
     * cerrada: no se pinta, pero sí entra al buscador) y 'busqueda' (ruta completa).
     *
     * @return Collection<int, array{id: string, label: string, activo: bool, items: array<int, array{label: string, href: string, activo: bool, nivel: int, oculto: bool, busqueda: string}>}>
     */
    public function grupos(User $usuario, Request $request): Collection
    {
        $puedeVerSecciones = $usuario->can('viewAny', Seccion::class);
        $fijos = collect(config('navegacion.grupos'))->keyBy('slug');
        $seccionActual = $request->routeIs('secciones.*') ? $request->route('seccion') : null;
        // La rama de la sección abierta (ella y sus ancestros) muestra sus subsecciones.
        $rama = $seccionActual instanceof Seccion
            ? array_merge([$seccionActual->id], array_map(fn (Seccion $ancestro) => $ancestro->id, $seccionActual->ancestros()))
            : [];

        return MenuGrupo::query()
            ->orderBy('orden')
            ->orderBy('id')
            ->when($puedeVerSecciones, fn ($consulta) => $consulta->with(['secciones' => fn ($secciones) => $secciones->where('is_visible', true)]))
            ->get()
            ->map(function (MenuGrupo $grupo) use ($usuario, $request, $fijos, $puedeVerSecciones, $seccionActual, $rama): array {
                $config = $fijos->get($grupo->slug, ['items' => [], 'patrones' => []]);

                $items = collect($config['items'])
                    ->filter(fn (array $item) => $usuario->hasAnyRole($item['roles']))
                    ->map(fn (array $item) => [
                        'label' => $item['label'],
                        'href' => route($item['ruta']),
                        'activo' => $request->routeIs($item['patron']) && ! $request->routeIs($item['excepto'] ?? ''),
                        'nivel' => 0,
                        'oculto' => false,
                        'busqueda' => $item['label'],
                    ]);

                if ($puedeVerSecciones) {
                    $porId = $grupo->secciones->keyBy('id');
                    $items = $items->concat($this->itemsDeSecciones(
                        ArbolSecciones::construir($grupo->secciones), 0, true, $seccionActual, $rama, $porId,
                    ));
                }

                $items = $items->values();
                $patrones = $config['patrones'] ?: [''];

                return [
                    'id' => $grupo->slug,
                    'label' => $grupo->nombre,
                    'activo' => $request->routeIs(...$patrones) || $items->contains('activo', true),
                    'items' => $items->all(),
                ];
            })
            ->filter(fn (array $grupo) => $grupo['items'] !== [])
            ->values();
    }

    /**
     * Recorre el árbol en orden. Las subsecciones solo se pintan en la rama
     * abierta y, por nivel, hasta SUBSECCIONES_VISIBLES (más las de la propia
     * rama); el resto se resume en un enlace "Ver N más…" a la sección padre.
     * Las no pintadas salen con 'oculto' para que el buscador las encuentre.
     *
     * @param  Collection<int, Seccion>  $hermanas
     * @param  list<int>  $rama
     * @param  Collection<int, Seccion>  $porId
     * @return list<array<string, mixed>>
     */
    private function itemsDeSecciones(Collection $hermanas, int $nivel, bool $visible, mixed $seccionActual, array $rama, Collection $porId): array
    {
        $items = [];

        foreach ($hermanas as $seccion) {
            $items[] = $this->itemDeSeccion($seccion, $nivel, $seccionActual, $rama, $porId, ! $visible);

            $hijas = $seccion->getRelation('hijas');
            $abierta = $visible && in_array($seccion->id, $rama, true);
            $ocultas = 0;

            foreach ($hijas->values() as $posicion => $hija) {
                $pintar = $abierta && ($posicion < self::SUBSECCIONES_VISIBLES || in_array($hija->id, $rama, true));
                $ocultas += $abierta && ! $pintar ? 1 : 0;
                $items = array_merge($items, $this->itemsDeSecciones(collect([$hija]), $nivel + 1, $pintar, $seccionActual, $rama, $porId));
            }

            if ($ocultas > 0) {
                $items[] = [
                    'label' => __('Ver :count más…', ['count' => $ocultas]),
                    'href' => route('secciones.show', $seccion),
                    'activo' => false,
                    'nivel' => $nivel + 1,
                    'oculto' => false,
                    'busqueda' => '',
                    'esMas' => true,
                ];
            }
        }

        return $items;
    }

    /**
     * @param  list<int>  $rama
     * @param  Collection<int, Seccion>  $porId  secciones visibles del grupo, para armar la ruta
     * @return array{label: string, href: string, activo: bool, nivel: int, oculto: bool, busqueda: string}
     */
    private function itemDeSeccion(Seccion $seccion, int $nivel, mixed $seccionActual, array $rama, Collection $porId, bool $oculto = false): array
    {
        $padre = $seccion->parent_id;
        $ruta = [$seccion->nombre];

        for ($actual = $porId->get($padre); $actual !== null && count($ruta) <= Seccion::PROFUNDIDAD_MAXIMA; $actual = $porId->get($actual->parent_id)) {
            array_unshift($ruta, $actual->nombre);
        }

        return [
            'label' => $seccion->nombre,
            'href' => route('secciones.show', $seccion),
            'activo' => $seccionActual instanceof Seccion && $seccionActual->is($seccion),
            'nivel' => $nivel,
            'oculto' => $oculto,
            'busqueda' => implode(' › ', $ruta),
            'esMas' => false,
        ];
    }
}
