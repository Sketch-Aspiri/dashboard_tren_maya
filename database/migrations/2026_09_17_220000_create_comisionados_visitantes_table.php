<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Etapa 2 of "Control de Asistencia Diaria" (see the approved plan).
     * Personnel from OTHER coordinations/departments physically present at
     * one of Zona Oriente's stations today. They are deliberately NOT a
     * foreign key to `empleados` — they are not part of Zona Oriente's own
     * roster, just free-text entries (name, home department, reason).
     */
    public function up(): void
    {
        Schema::create('comisionados_visitantes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estacion_id')->constrained('estaciones')->restrictOnDelete();
            $table->date('fecha');
            $table->string('no_trabajador')->nullable();
            $table->string('nombre');
            $table->string('direccion_origen')->nullable();
            $table->string('motivo')->nullable();
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();
            $table->text('notas')->nullable();
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['estacion_id', 'fecha']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('comisionados_visitantes');
    }
};
