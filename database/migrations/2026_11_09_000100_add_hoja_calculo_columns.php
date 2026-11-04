<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hoja de cálculo sobre las tablas de las secciones:
 *
 * - seccion_filas.orden: posición de la fila en la hoja (permite insertar
 *   filas entre otras). NULL = la del id: las inserciones masivas
 *   (importación, nube) no la llenan y quedan al final, en su orden.
 * - seccion_filas.formato: estilo por celda, indexado por clave de columna.
 * - seccion_columnas.ancho: ancho de la columna en px (NULL = el normal).
 * - seccion_tablas.diseno: celdas combinadas y rangos con formato de tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seccion_filas', function (Blueprint $table) {
            $table->double('orden')->nullable()->after('datos');
            $table->json('formato')->nullable()->after('orden');
            $table->index(['seccion_tabla_id', 'orden']);
        });

        DB::table('seccion_filas')->update(['orden' => DB::raw('id')]);

        Schema::table('seccion_columnas', function (Blueprint $table) {
            $table->unsignedSmallInteger('ancho')->nullable()->after('orden');
        });

        Schema::table('seccion_tablas', function (Blueprint $table) {
            $table->json('diseno')->nullable()->after('orden');
        });
    }

    public function down(): void
    {
        Schema::table('seccion_tablas', function (Blueprint $table) {
            $table->dropColumn('diseno');
        });

        Schema::table('seccion_columnas', function (Blueprint $table) {
            $table->dropColumn('ancho');
        });

        Schema::table('seccion_filas', function (Blueprint $table) {
            $table->dropIndex(['seccion_tabla_id', 'orden']);
            $table->dropColumn(['orden', 'formato']);
        });
    }
};
