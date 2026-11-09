<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ciclo posterior a la aprobación: envío del ANEXO a la Coordinación (con
     * su número de oficio) y respuesta de la Coordinación, que puede corregir
     * la partida presupuestal de cada renglón (columna P.Ptal/Fin), quitar
     * renglones o agregar otros. También marca los proveedores que retienen
     * ISR (persona física) para validar sus facturas.
     */
    public function up(): void
    {
        Schema::table('solicitudes_insumos', function (Blueprint $table) {
            $table->string('oficio_numero', 20)->nullable()->after('comentario_zona');
            $table->timestamp('enviada_coordinacion_at')->nullable()->after('oficio_numero');
            $table->timestamp('devuelta_at')->nullable()->after('enviada_coordinacion_at');
        });

        Schema::table('solicitud_insumo_partidas', function (Blueprint $table) {
            $table->string('partida_final', 10)->nullable()->after('partida_presupuestal');
            $table->boolean('eliminada_por_coordinacion')->default(false)->after('partida_final');
            $table->boolean('agregada_por_coordinacion')->default(false)->after('eliminada_por_coordinacion');
        });

        Schema::table('proveedores', function (Blueprint $table) {
            $table->boolean('retiene_isr')->default(false)->after('iva');
        });
    }

    public function down(): void
    {
        Schema::table('proveedores', function (Blueprint $table) {
            $table->dropColumn('retiene_isr');
        });

        Schema::table('solicitud_insumo_partidas', function (Blueprint $table) {
            $table->dropColumn(['partida_final', 'eliminada_por_coordinacion', 'agregada_por_coordinacion']);
        });

        Schema::table('solicitudes_insumos', function (Blueprint $table) {
            $table->dropColumn(['oficio_numero', 'enviada_coordinacion_at', 'devuelta_at']);
        });
    }
};
