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
    /**
     * @return Collection<int, array{id: string, label: string, activo: bool, items: array<int, array{label: string, href: string, activo: bool}>}>
     */
    public function grupos(User $usuario, Request $request): Collection
    {
        $puedeVerSecciones = $usuario->can('viewAny', Seccion::class);
        $fijos = collect(config('navegacion.grupos'))->keyBy('slug');
        $seccionActual = $request->routeIs('secciones.*') ? $request->route('seccion') : null;

        return MenuGrupo::query()
            ->orderBy('orden')
            ->orderBy('id')
            ->when($puedeVerSecciones, fn ($consulta) => $consulta->with(['secciones' => fn ($secciones) => $secciones->where('is_visible', true)]))
            ->get()
            ->map(function (MenuGrupo $grupo) use ($usuario, $request, $fijos, $puedeVerSecciones, $seccionActual): array {
                $config = $fijos->get($grupo->slug, ['items' => [], 'patrones' => []]);

                $items = collect($config['items'])
                    ->filter(fn (array $item) => $usuario->hasAnyRole($item['roles']))
                    ->map(fn (array $item) => [
                        'label' => $item['label'],
                        'href' => route($item['ruta']),
                        'activo' => $request->routeIs($item['patron']) && ! $request->routeIs($item['excepto'] ?? ''),
                    ]);

                if ($puedeVerSecciones) {
                    $items = $items->concat($grupo->secciones->map(fn (Seccion $seccion) => [
                        'label' => $seccion->nombre,
                        'href' => route('secciones.show', $seccion),
                        'activo' => $seccionActual instanceof Seccion && $seccionActual->is($seccion),
                    ]));
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
}
