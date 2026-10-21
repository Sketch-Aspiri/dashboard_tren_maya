<?php

namespace App\Services\Secciones;

use App\Models\MenuGrupo;
use App\Models\Seccion;
use App\Support\Reordenador;
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
     * @param  array{menu_grupo_id: int, nombre: string, descripcion?: string|null}  $datos
     */
    public function crearSeccion(array $datos): Seccion
    {
        $seccion = Seccion::query()->create([
            'menu_grupo_id' => $datos['menu_grupo_id'],
            'nombre' => $datos['nombre'],
            'descripcion' => $datos['descripcion'] ?? null,
            'slug' => $this->slugUnico(Seccion::class, $datos['nombre']),
            'orden' => (int) Seccion::query()->where('menu_grupo_id', $datos['menu_grupo_id'])->max('orden') + 1,
            'is_visible' => true,
        ]);

        // Toda sección nace con su tabla por defecto.
        $seccion->tablaActiva();

        return $seccion;
    }

    /**
     * El slug (URL) no cambia al renombrar: los enlaces guardados siguen
     * funcionando.
     *
     * @param  array{menu_grupo_id?: int, nombre?: string, descripcion?: string|null, is_visible?: bool}  $datos
     */
    public function actualizarSeccion(Seccion $seccion, array $datos): Seccion
    {
        if (isset($datos['menu_grupo_id']) && (int) $datos['menu_grupo_id'] !== $seccion->menu_grupo_id) {
            $datos['orden'] = (int) Seccion::query()->where('menu_grupo_id', $datos['menu_grupo_id'])->max('orden') + 1;
        }

        $seccion->update($datos);

        return $seccion;
    }

    /**
     * Baja lógica: la sección desaparece del menú pero su tabla y
     * documentos siguen en la base (y en la bitácora).
     */
    public function eliminarSeccion(Seccion $seccion): void
    {
        $seccion->delete();
    }

    public function moverSeccion(Seccion $seccion, string $direccion): void
    {
        Reordenador::mover(
            Seccion::query()->where('menu_grupo_id', $seccion->menu_grupo_id)->orderBy('orden')->orderBy('nombre')->get(),
            $seccion,
            $direccion,
        );
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
