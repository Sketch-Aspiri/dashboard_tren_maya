<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Monto pagado por un servicio de estación en un mes. Una fila por
     * servicio+año+mes; un pago de $0 ("$ -" en el Excel) se guarda como 0,
     * un mes sin dato simplemente no tiene fila.
     */
    public function up(): void
    {
        Schema::create('pagos_servicio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('servicio_estacion_id')->constrained('servicios_estacion')->cascadeOnDelete();
            $table->unsignedSmallInteger('anio');
            $table->unsignedTinyInteger('mes');
            $table->decimal('monto', 12, 2);
            $table->timestamps();

            $table->unique(['servicio_estacion_id', 'anio', 'mes']);
            $table->index(['anio', 'mes']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pagos_servicio');
    }
};
