<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clave de la columna con el nombre de la estación, usada para etiquetar en
 * bloque. Se recuerda para que las cargas y sincronizaciones posteriores
 * etiqueten las filas nuevas solas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seccion_tablas', function (Blueprint $table) {
            $table->string('columna_estacion_clave', 64)->nullable()->after('orden');
        });
    }

    public function down(): void
    {
        Schema::table('seccion_tablas', function (Blueprint $table) {
            $table->dropColumn('columna_estacion_clave');
        });
    }
};
