<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estación dueña del documento (nullable: los existentes quedan solo de zona).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seccion_archivos', function (Blueprint $table) {
            $table->foreignId('estacion_id')->nullable()->after('seccion_fila_id')
                ->constrained('estaciones')->nullOnDelete();
            $table->index(['seccion_id', 'estacion_id', 'deleted_at'], 'seccion_archivos_seccion_estacion_idx');
        });
    }

    public function down(): void
    {
        Schema::table('seccion_archivos', function (Blueprint $table) {
            $table->dropIndex('seccion_archivos_seccion_estacion_idx');
            $table->dropConstrainedForeignId('estacion_id');
        });
    }
};
