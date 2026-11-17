<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solicitud_insumo_partidas', function (Blueprint $table) {
            $table->string('ficha_tecnica_ruta')->nullable();
            $table->string('ficha_tecnica_nombre')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('solicitud_insumo_partidas', function (Blueprint $table) {
            $table->dropColumn(['ficha_tecnica_ruta', 'ficha_tecnica_nombre']);
        });
    }
};
