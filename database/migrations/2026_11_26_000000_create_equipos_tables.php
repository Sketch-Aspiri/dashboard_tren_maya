<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Los siete equipos originales: clave => [nombre, grupo del menú que les pertenece]. */
    private const BASE = [
        'rh' => ['Recursos Humanos', 'rrhh'],
        'rm' => ['Recursos Materiales', 'recursos-materiales'],
        'mantenimiento' => ['Mantenimiento', null],
        'seguridad' => ['Seguridad Física', 'sgd-fis'],
        'finanzas' => ['Finanzas y comercialización', 'recursos-financieros'],
        'administracion' => ['Administración', null],
        'soporte' => ['Soporte del Sistema', null],
    ];

    public function up(): void
    {
        Schema::create('equipos', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 32)->unique();
            $table->string('nombre', 100);
            $table->timestamps();
        });

        // Un equipo solo existe en las estaciones listadas aquí (no todas tienen los mismos equipos).
        Schema::create('equipo_estacion', function (Blueprint $table) {
            $table->foreignId('equipo_id')->constrained('equipos')->cascadeOnDelete();
            $table->foreignId('estacion_id')->constrained('estaciones')->cascadeOnDelete();
            $table->primary(['equipo_id', 'estacion_id']);
        });

        // Cada grupo del menú pertenece a lo sumo a UN equipo (la restricción única lo garantiza).
        Schema::create('equipo_menu_grupo', function (Blueprint $table) {
            $table->foreignId('equipo_id')->constrained('equipos')->cascadeOnDelete();
            $table->foreignId('menu_grupo_id')->unique()->constrained('menu_grupos')->cascadeOnDelete();
            $table->primary(['equipo_id', 'menu_grupo_id']);
        });

        $estaciones = DB::table('estaciones')->where('is_operativa', true)->pluck('id');

        foreach (self::BASE as $clave => [$nombre, $grupo]) {
            $id = DB::table('equipos')->insertGetId(['clave' => $clave, 'nombre' => $nombre, 'created_at' => now(), 'updated_at' => now()]);

            DB::table('equipo_estacion')->insert($estaciones->map(fn ($estacion) => ['equipo_id' => $id, 'estacion_id' => $estacion])->all());

            $grupoId = $grupo === null ? null : DB::table('menu_grupos')->where('slug', $grupo)->value('id');
            if ($grupoId !== null) {
                DB::table('equipo_menu_grupo')->insert(['equipo_id' => $id, 'menu_grupo_id' => $grupoId]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('equipo_menu_grupo');
        Schema::dropIfExists('equipo_estacion');
        Schema::dropIfExists('equipos');
    }
};
