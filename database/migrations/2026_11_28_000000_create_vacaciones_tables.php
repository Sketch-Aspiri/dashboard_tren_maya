<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacacion_periodos', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('anio');
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('estado', 20)->default('vigente');
            $table->string('archivo_ruta')->nullable();
            $table->string('archivo_nombre')->nullable();
            $table->string('archivo_hash', 64)->nullable();
            $table->foreignId('subido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('publicado_at')->nullable();
            $table->timestamps();

            $table->unique(['anio', 'version']);
            $table->index(['estado', 'anio']);
        });

        Schema::create('vacacion_personas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('periodo_id')->constrained('vacacion_periodos')->cascadeOnDelete();
            $table->foreignId('estacion_id')->nullable()->constrained('estaciones')->nullOnDelete();
            $table->string('no_empleado', 30);
            $table->foreignId('empleado_id')->nullable()->constrained('empleados')->nullOnDelete();
            $table->string('nombre');
            $table->string('puesto')->nullable();
            $table->unsignedSmallInteger('dias_otorgados')->default(0);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();

            $table->unique(['periodo_id', 'no_empleado']);
            $table->index(['periodo_id', 'estacion_id']);
        });

        Schema::create('vacacion_tramos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('periodo_id')->constrained('vacacion_periodos')->cascadeOnDelete();
            $table->foreignId('persona_id')->constrained('vacacion_personas')->cascadeOnDelete();
            $table->foreignId('estacion_id')->nullable()->constrained('estaciones')->nullOnDelete();
            $table->unsignedTinyInteger('trimestre');
            $table->date('inicio')->nullable();
            $table->date('fin')->nullable();
            $table->unsignedSmallInteger('dias')->default(0);
            $table->string('origen', 20)->default('oficial');
            $table->date('inicio_oficial')->nullable();
            $table->date('fin_oficial')->nullable();
            $table->unsignedSmallInteger('dias_oficial')->default(0);
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('editado_at')->nullable();
            $table->string('nota', 500)->nullable();
            $table->timestamps();

            $table->unique(['persona_id', 'trimestre']);
            $table->index(['periodo_id', 'estacion_id']);
            $table->index(['inicio', 'fin']);
        });

        Schema::create('vacacion_solicitudes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('periodo_id')->constrained('vacacion_periodos')->cascadeOnDelete();
            $table->uuid('lote')->index();
            $table->foreignId('estacion_id')->constrained('estaciones')->cascadeOnDelete();
            $table->foreignId('tramo_id')->nullable()->constrained('vacacion_tramos')->nullOnDelete();
            $table->foreignId('solicitante_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('no_empleado', 30);
            $table->string('nombre');
            $table->unsignedTinyInteger('trimestre');
            $table->date('inicio_origen')->nullable();
            $table->date('fin_origen')->nullable();
            $table->unsignedSmallInteger('dias_origen')->default(0);
            $table->date('nuevo_inicio')->nullable();
            $table->date('nuevo_fin')->nullable();
            $table->unsignedSmallInteger('nuevos_dias')->default(0);
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
    }

    public function down(): void
    {
        Schema::dropIfExists('vacacion_solicitudes');
        Schema::dropIfExists('vacacion_tramos');
        Schema::dropIfExists('vacacion_personas');
        Schema::dropIfExists('vacacion_periodos');
    }
};
