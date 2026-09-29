<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Arma los grupos del menú (config/navegacion.php) para un usuario: solo los
 * enlaces que su rol puede ver, con su URL y estado activo ya resueltos.
 * Un grupo sin enlaces visibles se omite.
 */
class MenuNavegacion
{
    /**
     * @return Collection<int, array{id: string, label: string, activo: bool, items: array<int, array{label: string, href: string, activo: bool, pendiente: bool}>}>
     */
    public function grupos(User $usuario, Request $request): Collection
    {
        $puedeVerPendientes = $usuario->hasAnyRole(config('navegacion.roles_pendientes'));

        return collect(config('navegacion.grupos'))
            ->map(function (array $grupo) use ($usuario, $request, $puedeVerPendientes): array {
                $items = collect($grupo['items'])
                    ->filter(fn (array $item) => $usuario->hasAnyRole($item['roles']))
                    ->map(fn (array $item) => [
                        'label' => $item['label'],
                        'href' => route($item['ruta']),
                        'activo' => $request->routeIs($item['patron']) && ! $request->routeIs($item['excepto'] ?? ''),
                        'pendiente' => false,
                    ]);

                if ($puedeVerPendientes) {
                    $items = $items->concat(collect($grupo['pendientes'] ?? [])->map(function (string $label) use ($grupo, $request): array {
                        $slug = Str::slug($label);

                        return [
                            'label' => $label,
                            'href' => route('secciones.show', [$grupo['id'], $slug]),
                            'pendiente' => true,
                            'activo' => $request->routeIs('secciones.show')
                                && $request->route('grupo') === $grupo['id']
                                && $request->route('seccion') === $slug,
                        ];
                    }));
                }

                $items = $items->values();

                return [
                    'id' => $grupo['id'],
                    'label' => $grupo['label'],
                    'activo' => $request->routeIs(...($grupo['patrones'] ?: [''])) || $items->contains('activo', true),
                    'items' => $items->all(),
                ];
            })
            ->filter(fn (array $grupo) => $grupo['items'] !== [])
            ->values();
    }

    /**
     * Etiqueta y grupo de un concepto pendiente, o null si no existe.
     *
     * @return array{grupo: string, label: string}|null
     */
    public function buscarPendiente(string $grupoId, string $slug): ?array
    {
        $grupo = collect(config('navegacion.grupos'))->firstWhere('id', $grupoId);

        if ($grupo === null) {
            return null;
        }

        $label = collect($grupo['pendientes'] ?? [])->first(fn (string $label) => Str::slug($label) === $slug);

        return $label === null ? null : ['grupo' => $grupo['label'], 'label' => $label];
    }
}
