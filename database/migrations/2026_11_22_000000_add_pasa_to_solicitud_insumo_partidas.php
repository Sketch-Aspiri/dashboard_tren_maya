<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Casilla "Pasa" de la Zona: un insumo que no pasa se queda en la solicitud
 * pero no suma al total ni entra al consolidado ni al formato oficial.
 * Solo agrega una columna con valor verdadero por omisión (no toca datos).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solicitud_insumo_partidas', function (Blueprint $table) {
            $table->boolean('pasa')->default(true)->after('eliminada_por_coordinacion');
        });
    }

    public function down(): void
    {
        Schema::table('solicitud_insumo_partidas', function (Blueprint $table) {
            $table->dropColumn('pasa');
        });
    }
};
