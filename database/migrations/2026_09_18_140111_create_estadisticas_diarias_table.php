<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Módulo "Estadísticas" (flujo de pasajeros / boletos vendidos por
     * estación), ver el plan aprobado. Una fila por estación+fecha, y solo
     * se guarda cuando ese día ya tiene datos capturados — nunca ceros para
     * días futuros. Resúmenes mensuales/anuales se calculan con SUM()/
     * GROUP BY en EstadisticaDiariaService, no se persisten aparte.
     */
    public function up(): void
    {
        Schema::create('estadisticas_diarias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estacion_id')->constrained('estaciones')->restrictOnDelete();
            $table->date('fecha');
            $table->unsignedInteger('abordan');
            $table->unsignedInteger('boletos_vendidos');
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['estacion_id', 'fecha']);
            $table->index(['estacion_id', 'fecha']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('estadisticas_diarias');
    }
};
