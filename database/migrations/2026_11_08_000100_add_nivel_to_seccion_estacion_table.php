<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nivel del permiso de una estación sobre una sección: "ver" o "editar".
 *
 * Falla cerrada: antes una aprobación solo dejaba ver las filas etiquetadas
 * con la propia estación; ahora un permiso abre la sección completa. Por eso
 * las aprobaciones existentes quedan con nivel NULL ("pendiente de revisar"):
 * se conservan como referencia, pero no dan acceso hasta que el Jefe de Zona
 * o el Administrador elija Ver o Editar (VisibilidadSecciones ignora NULL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seccion_estacion', function (Blueprint $table) {
            $table->string('nivel', 10)->nullable()->after('estacion_id');
        });
    }

    public function down(): void
    {
        Schema::table('seccion_estacion', function (Blueprint $table) {
            $table->dropColumn('nivel');
        });
    }
};
