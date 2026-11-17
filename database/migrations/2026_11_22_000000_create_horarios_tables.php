<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('horario_periodos', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('anio');
            $table->unsignedTinyInteger('mes');
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('estado', 20)->default('vigente');
            $table->string('archivo_ruta')->nullable();
            $table->string('archivo_nombre')->nullable();
            $table->string('archivo_hash', 64)->nullable();
            $table->foreignId('subido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('publicado_at')->nullable();
            $table->timestamps();

            $table->unique(['anio', 'mes', 'version']);
            $table->index(['estado', 'anio', 'mes']);
        });

        Schema::create('horario_turnos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('periodo_id')->constrained('horario_periodos')->cascadeOnDelete();
            $table->foreignId('estacion_id')->nullable()->constrained('estaciones')->nullOnDelete();
            $table->string('no_empleado', 30);
            $table->foreignId('empleado_id')->nullable()->constrained('empleados')->nullOnDelete();
            $table->string('nombre');
            $table->string('puesto')->nullable();
            $table->date('fecha');
            $table->string('jornada');
            $table->string('comida', 100)->nullable();
            $table->string('semana', 30)->nullable();
            $table->string('origen', 20)->default('oficial');
            $table->string('jornada_oficial')->nullable();
            $table->string('comida_oficial', 100)->nullable();
            $table->timestamps();

            $table->index(['periodo_id', 'estacion_id', 'fecha']);
            $table->index(['periodo_id', 'no_empleado', 'fecha']);
        });

        Schema::create('horario_solicitudes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('periodo_id')->constrained('horario_periodos')->cascadeOnDelete();
            $table->foreignId('estacion_id')->constrained('estaciones')->cascadeOnDelete();
            $table->foreignId('turno_id')->nullable()->constrained('horario_turnos')->nullOnDelete();
            $table->foreignId('solicitante_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('no_empleado', 30);
            $table->string('nombre');
            $table->date('fecha');
            $table->string('tipo', 20);
            $table->string('jornada_origen');
            $table->string('comida_origen', 100)->nullable();
            $table->string('nueva_jornada')->nullable();
            $table->string('nueva_comida', 100)->nullable();
            $table->date('nueva_fecha')->nullable();
            $table->text('motivo');
            $table->string('estado', 20)->default('pendiente');
            $table->foreignId('resuelta_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resuelta_at')->nullable();
            $table->text('comentario_resolucion')->nullable();
            $table->boolean('aplicada')->default(false);
            $table->timestamps();

            $table->index(['periodo_id', 'estacion_id', 'estado']);
            $table->index(['estado', 'resuelta_at']);
        });

        Schema::create('horario_consolidados', function (Blueprint $table) {
            $table->id();
            $table->date('semana_inicio')->unique();
            $table->string('ruta');
            $table->string('nombre');
            $table->unsignedInteger('turnos')->default(0);
            $table->unsignedInteger('cambios')->default(0);
            $table->foreignId('generado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('horario_consolidados');
        Schema::dropIfExists('horario_solicitudes');
        Schema::dropIfExists('horario_turnos');
        Schema::dropIfExists('horario_periodos');
    }
};
