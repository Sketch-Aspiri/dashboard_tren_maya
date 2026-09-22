<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Módulo "Controles" -> Elevadores: mismo criterio que
     * escaleras_electricas (ver esa migración), pero con
     * estado_puertas_cabina_botoneras en vez de barandales/botón de paro.
     */
    public function up(): void
    {
        Schema::create('elevadores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estacion_id')->constrained('estaciones')->restrictOnDelete();
            $table->string('identificador')->nullable();
            $table->string('modelo')->nullable();
            $table->unsignedSmallInteger('anio_instalacion')->nullable();
            $table->string('tipo')->nullable();
            $table->string('operativo')->nullable();
            $table->date('fecha_ultimo_mantenimiento')->nullable();
            $table->string('estado_puertas_cabina_botoneras')->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('elevadores');
    }
};
