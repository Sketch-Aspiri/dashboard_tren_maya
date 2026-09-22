<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Módulo "Controles" -> Escaleras eléctricas: inventario de equipos por
     * estación, cargado desde el ANEXO
     * (app/Console/Commands/ImportControlesCommand.php). Varias escaleras
     * pueden pertenecer a la misma estación, por lo que no hay un único
     * registro por estación como en servicios_estacion.
     */
    public function up(): void
    {
        Schema::create('escaleras_electricas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estacion_id')->constrained('estaciones')->restrictOnDelete();
            $table->string('identificador')->nullable();
            $table->string('modelo')->nullable();
            $table->unsignedSmallInteger('anio_instalacion')->nullable();
            $table->string('tipo')->nullable();
            // Texto crudo del origen ("SI", "si", "NO"...) — nunca se
            // normaliza a booleano para no perder matices como
            // "SI (FUERA DE OPERACION)" que aparecen en la hoja de elevadores.
            $table->string('operativo')->nullable();
            $table->string('estado_barandales')->nullable();
            $table->string('estado_boton_paro_emergencia')->nullable();
            $table->date('fecha_ultimo_mantenimiento')->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('escaleras_electricas');
    }
};
