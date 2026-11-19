<?php

namespace Database\Seeders;

use App\Models\Equipo;
use App\Models\MenuGrupo;
use App\Support\Areas;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Asigna los grupos originales del menú a sus equipos (RR.HH. → RH, etc.) cuando el grupo todavía no es de nadie.
 * Idempotente: no mueve un grupo que el administrador ya reasignó.
 */
class EquipoSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Areas::GRUPOS_BASE as $slug => $clave) {
            $equipo = Equipo::query()->where('clave', $clave)->first();
            $grupo = MenuGrupo::query()->where('slug', $slug)->first();

            if ($equipo === null || $grupo === null || DB::table('equipo_menu_grupo')->where('menu_grupo_id', $grupo->id)->exists()) {
                continue;
            }

            $equipo->menuGrupos()->attach($grupo->id);
        }

        Cache::forget(Equipo::CACHE);
    }
}
