<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estación dueña de la fila (nullable: las filas existentes quedan sin
 * etiqueta y solo las ve la zona). nullOnDelete: si una estación dejara de
 * existir, sus filas vuelven a ser solo de zona (falla cerrada).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seccion_filas', function (Blueprint $table) {
            $table->foreignId('estacion_id')->nullable()->after('seccion_tabla_id')
                ->constrained('estaciones')->nullOnDelete();
            $table->index(['seccion_tabla_id', 'estacion_id', 'deleted_at'], 'seccion_filas_tabla_estacion_idx');
        });
    }

    public function down(): void
    {
        Schema::table('seccion_filas', function (Blueprint $table) {
            $table->dropIndex('seccion_filas_tabla_estacion_idx');
            $table->dropConstrainedForeignId('estacion_id');
        });
    }
};
