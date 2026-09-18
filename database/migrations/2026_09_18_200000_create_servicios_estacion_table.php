<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * "Estadísticas" -> Gasto energético: un servicio (agua o energía
     * eléctrica) contratado por una estación, con su proveedor, contrato y
     * las observaciones del informe. Los pagos mensuales viven en
     * pagos_servicio.
     */
    public function up(): void
    {
        Schema::create('servicios_estacion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estacion_id')->constrained('estaciones')->restrictOnDelete();
            $table->string('tipo', 30);
            $table->string('proveedor')->nullable();
            $table->string('contrato')->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamps();

            $table->unique(['estacion_id', 'tipo']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('servicios_estacion');
    }
};
