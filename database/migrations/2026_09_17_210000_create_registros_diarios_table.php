<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Daily attendance capture, Fase 1 of "Control de Asistencia Diaria"
     * (see the approved plan). One row per empleado per fecha
     * (`unique(empleado_id, fecha)`).
     *
     * `estacion_id` is deliberately denormalized from the empleado at
     * write time (not derived via a join) so Policy scoping checks and
     * "who has captured today" queries never need to join through
     * `empleados` — see RegistroDiarioPolicy and AsistenciaCapturaService.
     */
    public function up(): void
    {
        Schema::create('registros_diarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empleado_id')->constrained('empleados')->cascadeOnDelete();
            $table->foreignId('estacion_id')->constrained('estaciones')->restrictOnDelete();
            $table->date('fecha');
            $table->string('estatus')->default('presente');
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();
            $table->text('notas')->nullable();
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['empleado_id', 'fecha']);
            $table->index(['estacion_id', 'fecha']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('registros_diarios');
    }
};
