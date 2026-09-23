<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Módulo "Controles" -> Estatus de vías y andenes: una fila por vía de
     * cada estación (hoja "ZO_*" del ANEXO de escaleras/elevadores). Sin
     * llave natural estable en el origen, así que el importador reemplaza
     * el contenido completo de la tabla (ver ImportEstatusViasAndenesCommand).
     */
    public function up(): void
    {
        Schema::create('estatus_vias_andenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estacion_id')->constrained('estaciones')->restrictOnDelete();
            $table->unsignedSmallInteger('via');
            $table->string('anden', 10)->nullable();
            $table->string('estatus_anden')->nullable();
            $table->string('estatus_via')->nullable();
            $table->string('senaletica')->nullable();
            $table->string('teleindicadores')->nullable();
            $table->string('pruebas_galibo')->nullable();
            $table->text('riesgos_obstaculos')->nullable();
            $table->text('comentarios')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('estatus_vias_andenes');
    }
};
