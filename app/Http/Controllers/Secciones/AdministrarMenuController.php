<?php

namespace App\Http\Controllers\Secciones;

use App\Http\Controllers\Controller;
use App\Models\MenuGrupo;
use App\Models\Seccion;
use App\Support\ArbolSecciones;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

/**
 * "Administrar menú": grupos y secciones (con sus subsecciones), con alta,
 * cambio de nombre, orden, visibilidad y baja.
 */
class AdministrarMenuController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', MenuGrupo::class);
        $this->authorize('create', Seccion::class);

        $grupos = MenuGrupo::query()
            ->orderBy('orden')
            ->orderBy('id')
            ->with(['secciones' => fn ($consulta) => $consulta->withCount(['filas', 'archivos'])])
            ->get();

        return view('secciones.administrar', [
            'grupos' => $grupos,
            'ubicaciones' => $this->ubicaciones($grupos),
        ]);
    }

    /**
     * Destinos posibles al mover una sección: la raíz de cada grupo o
     * cualquier sección que aún admita subsecciones. 'ruta' lleva los ids de
     * la sección y sus ancestros para excluir los destinos inválidos (ella
     * misma y sus descendientes).
     *
     * @param  Collection<int, MenuGrupo>  $grupos
     * @return list<array{valor: string, etiqueta: string, ruta: list<int>}>
     */
    private function ubicaciones(Collection $grupos): array
    {
        $opciones = [];

        foreach ($grupos as $grupo) {
            $opciones[] = ['valor' => 'g-'.$grupo->id, 'etiqueta' => $grupo->nombre.' (raíz)', 'ruta' => []];

            $porId = $grupo->secciones->keyBy('id');

            foreach (ArbolSecciones::aplanar(ArbolSecciones::construir($grupo->secciones)) as $nodo) {
                if ($nodo['nivel'] + 1 >= Seccion::PROFUNDIDAD_MAXIMA) {
                    continue;
                }

                $nombres = [];
                $ruta = [];

                for ($actual = $nodo['seccion']; $actual !== null; $actual = $porId->get($actual->parent_id)) {
                    array_unshift($nombres, $actual->nombre);
                    array_unshift($ruta, $actual->id);
                }

                $opciones[] = [
                    'valor' => 's-'.$nodo['seccion']->id,
                    'etiqueta' => $grupo->nombre.' › '.implode(' › ', $nombres),
                    'ruta' => $ruta,
                ];
            }
        }

        return $opciones;
    }
}
