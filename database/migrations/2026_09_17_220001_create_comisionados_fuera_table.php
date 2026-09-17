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
     * Zona Oriente's OWN employees (real `Empleado` rows) commissioned OUT
     * to somewhere else today.
     *
     * Deliberately NO unique(empleado_id, fecha) constraint. Confirmed
     * against the real reference "oficio" document: an employee can have
     * an active `registros_diarios` status (e.g. "Vacaciones" with a date
     * range) AND a `comisionados_fuera` entry for the SAME day
     * simultaneously — the document simply concatenates both when
     * rendering. Unlike `registros_diarios`, this table is independent
     * and additive: multiple rows for the same empleado+fecha are
     * legitimate (e.g. a correction or a second commission note), not a
     * data error.
     */
    public function up(): void
    {
        Schema::create('comisionados_fuera', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empleado_id')->constrained('empleados')->cascadeOnDelete();
            // Denormalized from the empleado at write time, same rationale
            // as registros_diarios.estacion_id — Policy scoping never needs
            // to join through empleados.
            $table->foreignId('estacion_id')->constrained('estaciones')->restrictOnDelete();
            $table->date('fecha');
            $table->string('coordinacion_destino')->nullable();
            $table->string('ubicacion_destino')->nullable();
            $table->string('motivo')->nullable();
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();
            $table->text('notas')->nullable();
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['estacion_id', 'fecha']);
            $table->index('empleado_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('comisionados_fuera');
    }
};
