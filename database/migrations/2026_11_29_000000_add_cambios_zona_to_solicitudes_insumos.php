<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solicitudes_insumos', function (Blueprint $table) {
            // Cambios de producto (cantidad, unidad, descripción) que la Zona hizo al revisar.
            $table->json('cambios_zona')->nullable()->after('comentario_zona');
        });
    }

    public function down(): void
    {
        Schema::table('solicitudes_insumos', function (Blueprint $table) {
            $table->dropColumn('cambios_zona');
        });
    }
};
