<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solicitudes_insumos', function (Blueprint $table) {
            $table->dropColumn('justificacion_general');
        });
    }

    public function down(): void
    {
        Schema::table('solicitudes_insumos', function (Blueprint $table) {
            $table->text('justificacion_general')->nullable();
        });
    }
};
