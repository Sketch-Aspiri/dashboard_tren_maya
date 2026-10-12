<?php

namespace Database\Seeders;

use App\Models\MenuGrupo;
use App\Models\Seccion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Siembra los grupos del menú y sus secciones iniciales desde
 * config/navegacion.php. Idempotente y respetuoso de lo que el
 * administrador ya cambió: un grupo existente no se renombra, y una
 * sección que ya existe (aunque la hayan renombrado, movido o eliminado)
 * no se vuelve a crear.
 */
class MenuSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('navegacion.grupos') as $ordenGrupo => $definicion) {
            $grupo = MenuGrupo::query()->firstOrCreate(
                ['slug' => $definicion['slug']],
                ['nombre' => $definicion['nombre'], 'orden' => $ordenGrupo + 1],
            );

            foreach ($definicion['secciones'] as $ordenSeccion => $nombre) {
                $slug = Str::slug($nombre);

                if (Seccion::withTrashed()->where('slug', $slug)->exists()) {
                    continue;
                }

                Seccion::query()->create([
                    'menu_grupo_id' => $grupo->id,
                    'nombre' => $nombre,
                    'slug' => $slug,
                    'orden' => $ordenSeccion + 1,
                    'is_visible' => true,
                ]);
            }
        }
    }
}
