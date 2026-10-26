<?php

namespace App\Services\Secciones;

use App\Models\MenuGrupo;
use App\Models\Seccion;
use App\Support\Reordenador;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Alta, cambio de nombre, orden, visibilidad y baja de los grupos del menú
 * y de sus secciones ("Administrar menú").
 */
final class MenuService
{
    public function crearGrupo(string $nombre): MenuGrupo
    {
        return MenuGrupo::query()->create([
            'nombre' => $nombre,
            'slug' => $this->slugUnico(MenuGrupo::class, $nombre),
            'orden' => (int) MenuGrupo::query()->max('orden') + 1,
        ]);
    }

    public function renombrarGrupo(MenuGrupo $grupo, string $nombre): MenuGrupo
    {
        $grupo->update(['nombre' => $nombre]);

        return $grupo;
    }

    public function eliminarGrupo(MenuGrupo $grupo): void
    {
        if (Seccion::withTrashed()->where('menu_grupo_id', $grupo->id)->exists()) {
            throw ValidationException::withMessages([
                'grupo' => __('Solo se puede eliminar un grupo vacío. Mueve o elimina primero sus secciones.'),
            ]);
        }

        $grupo->delete();
    }

    public function moverGrupo(MenuGrupo $grupo, string $direccion): void
    {
        Reordenador::mover(MenuGrupo::query()->orderBy('orden')->orderBy('id')->get(), $grupo, $direccion);
    }

    /**
     * Con "parent_id" la sección cuelga de otra y hereda su grupo del menú.
     *
     * @param  array{menu_grupo_id?: int, parent_id?: int|null, nombre: string, descripcion?: string|null}  $datos
     */
    public function crearSeccion(array $datos): Seccion
    {
        $padre = isset($datos['parent_id']) ? Seccion::query()->findOrFail($datos['parent_id']) : null;
        $grupoId = $padre?->menu_grupo_id ?? $datos['menu_grupo_id'];

        if ($padre !== null) {
            $this->validarProfundidad($padre->nivel() + 1);
        }

        $seccion = Seccion::query()->create([
            'menu_grupo_id' => $grupoId,
            'parent_id' => $padre?->id,
            'nombre' => $datos['nombre'],
            'descripcion' => $datos['descripcion'] ?? null,
            'slug' => $this->slugUnico(Seccion::class, $datos['nombre']),
            'orden' => $this->siguienteOrden($grupoId, $padre?->id),
            'is_visible' => true,
        ]);

        // Toda sección nace con su tabla por defecto.
        $seccion->tablaActiva();

        return $seccion;
    }

    /**
     * El slug (URL) no cambia al renombrar: los enlaces guardados siguen
     * funcionando. Cambiar "parent_id" mueve la sección con todo su
     * contenido; las subsecciones siguen a su raíz al cambiar de grupo.
     *
     * @param  array{menu_grupo_id?: int, parent_id?: int|null, nombre?: string, descripcion?: string|null, is_visible?: bool, solo_documentos?: bool}  $datos
     */
    public function actualizarSeccion(Seccion $seccion, array $datos): Seccion
    {
        $cambiaPadre = array_key_exists('parent_id', $datos) && $datos['parent_id'] !== $seccion->parent_id;

        if ($cambiaPadre && $datos['parent_id'] !== null) {
            $padre = Seccion::query()->findOrFail($datos['parent_id']);
            $this->validarMovimiento($seccion, $padre);
            $datos['menu_grupo_id'] = $padre->menu_grupo_id;
        }

        $cambiaGrupo = isset($datos['menu_grupo_id']) && (int) $datos['menu_grupo_id'] !== $seccion->menu_grupo_id;

        // Una subsección no cambia de grupo por separado: solo al moverla de padre o a la raíz.
        if ($cambiaGrupo && ! $cambiaPadre && $seccion->parent_id !== null) {
            unset($datos['menu_grupo_id']);
            $cambiaGrupo = false;
        }

        if ($cambiaPadre || $cambiaGrupo) {
            $destinoPadre = $cambiaPadre ? $datos['parent_id'] : $seccion->parent_id;
            $destinoGrupo = $datos['menu_grupo_id'] ?? $seccion->menu_grupo_id;
            $datos['orden'] = $this->siguienteOrden($destinoGrupo, $destinoPadre);
        }

        DB::transaction(function () use ($seccion, $datos, $cambiaGrupo, $cambiaPadre): void {
            $seccion->update($datos);

            if ($cambiaGrupo || $cambiaPadre) {
                $this->propagarGrupo($seccion);
            }
        });

        return $seccion;
    }

    /**
     * Baja lógica: la sección y sus subsecciones desaparecen del menú pero
     * sus tablas y documentos siguen en la base (y en la bitácora).
     */
    public function eliminarSeccion(Seccion $seccion): void
    {
        DB::transaction(function () use ($seccion): void {
            // Una por una (no en bloque) para que cada baja quede en la bitácora.
            Seccion::query()->whereIn('id', $seccion->descendientesIds())->get()->each->delete();

            $seccion->delete();
        });
    }

    public function moverSeccion(Seccion $seccion, string $direccion): void
    {
        Reordenador::mover(
            Seccion::query()
                ->where('menu_grupo_id', $seccion->menu_grupo_id)
                ->where('parent_id', $seccion->parent_id)
                ->orderBy('orden')->orderBy('nombre')->get(),
            $seccion,
            $direccion,
        );
    }

    private function siguienteOrden(int $grupoId, ?int $padreId): int
    {
        return (int) Seccion::query()
            ->where('menu_grupo_id', $grupoId)
            ->where('parent_id', $padreId)
            ->max('orden') + 1;
    }

    /** Las descendientes comparten siempre el grupo de su raíz. */
    private function propagarGrupo(Seccion $seccion): void
    {
        $ids = $seccion->descendientesIds();

        if ($ids !== []) {
            Seccion::query()->whereIn('id', $ids)->update(['menu_grupo_id' => $seccion->menu_grupo_id]);
        }
    }

    private function validarMovimiento(Seccion $seccion, Seccion $padre): void
    {
        if ($padre->is($seccion) || in_array($padre->id, $seccion->descendientesIds(), true)) {
            throw ValidationException::withMessages([
                'parent_id' => __('Una sección no puede quedar dentro de sí misma ni de sus subsecciones.'),
            ]);
        }

        $this->validarProfundidad($padre->nivel() + 1 + $this->alturaSubarbol($seccion));
    }

    /** Niveles que cuelgan de la sección (0 si no tiene subsecciones). */
    private function alturaSubarbol(Seccion $seccion): int
    {
        $altura = 0;
        $nivel = [$seccion->id];

        while ($altura < Seccion::PROFUNDIDAD_MAXIMA
            && ($nivel = Seccion::query()->whereIn('parent_id', $nivel)->pluck('id')->all()) !== []) {
            $altura++;
        }

        return $altura;
    }

    private function validarProfundidad(int $nivelResultante): void
    {
        if ($nivelResultante > Seccion::PROFUNDIDAD_MAXIMA) {
            throw ValidationException::withMessages([
                'parent_id' => __('Las subsecciones admiten como máximo :n niveles.', ['n' => Seccion::PROFUNDIDAD_MAXIMA]),
            ]);
        }
    }

    /**
     * @param  class-string<MenuGrupo|Seccion>  $modelo
     */
    private function slugUnico(string $modelo, string $nombre): string
    {
        $base = Str::limit(Str::slug($nombre), 120, '') ?: 'seccion';
        $slug = $base;
        $sufijo = 2;

        $consulta = fn (string $candidato) => $modelo === Seccion::class
            ? Seccion::withTrashed()->where('slug', $candidato)->exists()
            : MenuGrupo::query()->where('slug', $candidato)->exists();

        // "administrar" es una ruta fija de /secciones.
        while ($slug === 'administrar' || $consulta($slug)) {
            $slug = "{$base}-{$sufijo}";
            $sufijo++;
        }

        return $slug;
    }
}
