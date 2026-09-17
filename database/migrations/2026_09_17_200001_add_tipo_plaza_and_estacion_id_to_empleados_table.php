<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `estacion_codigo` (legacy/historical, sourced directly from the
     * Excel roster) is preserved as-is — `estacion_id` is the new
     * normalized link to the `estaciones` catalog, populated by
     * app:link-empleados-estaciones.
     */
    public function up(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            $table->string('tipo_plaza')->default('permanente')->after('estatus');
            $table->foreignId('estacion_id')->nullable()->after('estacion_codigo')
                ->constrained('estaciones')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            $table->dropConstrainedForeignId('estacion_id');
            $table->dropColumn('tipo_plaza');
        });
    }
};
