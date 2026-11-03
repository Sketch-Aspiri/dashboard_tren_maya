<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Compartida con todas las estaciones" pasa de sí/no a un nivel: null (no
 * compartida), "ver" o "editar".
 *
 * Falla cerrada (igual que 2026_11_08_000100): una sección compartida con
 * todas antes solo mostraba a cada estación sus filas; ahora "todas" abriría
 * la sección completa a todas. Por eso NO se convierte sola: queda en null y
 * el compartido anterior se conserva como aprobaciones pendientes de revisar
 * (nivel NULL) para cada estación, visibles en el modal de permisos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('secciones', function (Blueprint $table) {
            $table->string('acceso_todas', 10)->nullable()->after('solo_documentos');
        });

        $ahora = now();
        $estaciones = DB::table('estaciones')->pluck('id');

        foreach (DB::table('secciones')->where('compartida_todas', true)->pluck('id') as $seccionId) {
            $existentes = DB::table('seccion_estacion')->where('seccion_id', $seccionId)->pluck('estacion_id')->flip();

            $pendientes = $estaciones
                ->reject(fn ($estacionId) => $existentes->has($estacionId))
                ->map(fn ($estacionId) => [
                    'seccion_id' => $seccionId, 'estacion_id' => $estacionId, 'nivel' => null,
                    'created_at' => $ahora, 'updated_at' => $ahora,
                ])
                ->values()
                ->all();

            DB::table('seccion_estacion')->insert($pendientes);
        }

        Schema::table('secciones', function (Blueprint $table) {
            $table->dropColumn('compartida_todas');
        });
    }

    public function down(): void
    {
        Schema::table('secciones', function (Blueprint $table) {
            $table->boolean('compartida_todas')->default(false)->after('solo_documentos');
        });

        DB::table('secciones')->whereNotNull('acceso_todas')->update(['compartida_todas' => true]);

        Schema::table('secciones', function (Blueprint $table) {
            $table->dropColumn('acceso_todas');
        });
    }
};
