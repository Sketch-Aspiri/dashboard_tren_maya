<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Instant taken BEFORE the attendance data was read to build the files.
     * "Desactualizado" compares later edits against it, so an edit made while
     * the (slow) PDF conversion was running is never silently missed.
     */
    public function up(): void
    {
        Schema::table('documentos_asistencia', function (Blueprint $table) {
            $table->timestamp('datos_al')->nullable()->after('es_suplencia');
        });
    }

    public function down(): void
    {
        Schema::table('documentos_asistencia', function (Blueprint $table) {
            $table->dropColumn('datos_al');
        });
    }
};
