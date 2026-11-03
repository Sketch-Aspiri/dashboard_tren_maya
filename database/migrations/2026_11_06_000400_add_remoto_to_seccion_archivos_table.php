<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Identidad del archivo en la nube. Los documentos existentes (y los
     * subidos a mano) dejan estas columnas en NULL; MySQL permite varios
     * NULL en un índice único.
     */
    public function up(): void
    {
        Schema::table('seccion_archivos', function (Blueprint $table) {
            $table->string('remoto_id', 255)->nullable()->after('seccion_origen_id');
            $table->string('remoto_ruta', 1000)->nullable()->after('remoto_id');
            $table->string('remoto_version', 255)->nullable()->after('remoto_ruta');
            $table->timestamp('remoto_modificado_en')->nullable()->after('remoto_version');

            $table->unique(['seccion_origen_id', 'remoto_id'], 'seccion_archivos_origen_remoto_unique');
        });
    }

    public function down(): void
    {
        Schema::table('seccion_archivos', function (Blueprint $table) {
            // La FK de seccion_origen_id usa el índice único: se le da uno propio antes de quitarlo.
            $table->index('seccion_origen_id', 'seccion_archivos_origen_idx');
            $table->dropUnique('seccion_archivos_origen_remoto_unique');
            $table->dropColumn(['remoto_id', 'remoto_ruta', 'remoto_version', 'remoto_modificado_en']);
        });
    }
};
