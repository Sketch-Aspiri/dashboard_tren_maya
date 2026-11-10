<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resultado de la consulta al SAT (servicio público de estado del CFDI):
 * `verificacion_sat` ya existía y guarda VIGENTE, CANCELADO, NO_ENCONTRADO,
 * ERROR o NO_VERIFICADO; aquí se agrega el detalle y la fecha de la consulta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->string('sat_es_cancelable', 60)->nullable()->after('verificacion_sat');
            $table->string('sat_estatus_cancelacion', 60)->nullable()->after('sat_es_cancelable');
            $table->string('sat_detalle', 255)->nullable()->after('sat_estatus_cancelacion');
            $table->timestamp('sat_consultado_at')->nullable()->after('sat_detalle');
        });
    }

    public function down(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->dropColumn(['sat_es_cancelable', 'sat_estatus_cancelacion', 'sat_detalle', 'sat_consultado_at']);
        });
    }
};
