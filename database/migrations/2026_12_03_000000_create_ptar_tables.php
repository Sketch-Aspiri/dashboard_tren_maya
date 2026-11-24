<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ptar_plantas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estacion_id')->unique()->constrained('estaciones')->cascadeOnDelete();
            $table->unsignedSmallInteger('numero')->nullable();
            $table->string('tramo', 12)->nullable();
            $table->json('normas')->nullable();
            $table->string('tipo', 80)->nullable();
            $table->string('empresa_operadora', 80)->nullable();
            $table->boolean('activa')->default(true);
            $table->timestamps();
        });

        Schema::create('ptar_registros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estacion_id')->constrained('estaciones')->cascadeOnDelete();
            $table->unsignedSmallInteger('anio');
            $table->unsignedTinyInteger('mes');
            $table->string('estado', 12)->default('borrador');
            $table->string('modo', 16)->default('sin_dato');
            $table->string('modo_detalle', 255)->nullable();
            $table->string('operador', 160)->nullable();
            $table->text('observaciones_equipos')->nullable();
            $table->text('observaciones_generales')->nullable();
            $table->timestamp('enviada_at')->nullable();
            $table->foreignId('revisada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revisada_at')->nullable();
            $table->text('comentario_zona')->nullable();
            $table->timestamps();

            $table->unique(['estacion_id', 'anio', 'mes']);
            $table->index(['anio', 'mes', 'estado']);
        });

        Schema::create('ptar_equipos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registro_id')->constrained('ptar_registros')->cascadeOnDelete();
            $table->string('equipo', 24);
            $table->string('estatus', 16)->default('sin_dato');
            $table->text('observaciones')->nullable();
            $table->timestamps();

            $table->unique(['registro_id', 'equipo']);
        });

        Schema::create('ptar_actividades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registro_id')->constrained('ptar_registros')->cascadeOnDelete();
            $table->string('actividad', 60);
            $table->date('ultima_fecha')->nullable();
            $table->string('nota', 255)->nullable();
            $table->timestamps();

            $table->unique(['registro_id', 'actividad']);
        });

        Schema::create('ptar_evidencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registro_id')->constrained('ptar_registros')->cascadeOnDelete();
            $table->string('ruta');
            $table->string('nombre');
            $table->string('pie', 255)->nullable();
            $table->string('origen', 12)->default('captura');
            $table->foreignId('capturo_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ptar_hallazgos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('planta_id')->constrained('ptar_plantas')->cascadeOnDelete();
            $table->foreignId('registro_origen_id')->nullable()->constrained('ptar_registros')->nullOnDelete();
            $table->string('tipo', 12)->default('problematica');
            $table->string('equipo', 24)->nullable();
            $table->text('descripcion');
            $table->text('propuesta')->nullable();
            $table->string('prioridad', 8)->default('media');
            $table->string('estado', 12)->default('abierto');
            $table->date('fecha_detectado')->nullable();
            $table->date('fecha_compromiso')->nullable();
            $table->date('fecha_cierre')->nullable();
            $table->string('tarjeta', 60)->nullable();
            $table->timestamp('tickets_enviado_at')->nullable();
            $table->foreignId('capturo_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['planta_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ptar_hallazgos');
        Schema::dropIfExists('ptar_evidencias');
        Schema::dropIfExists('ptar_actividades');
        Schema::dropIfExists('ptar_equipos');
        Schema::dropIfExists('ptar_registros');
        Schema::dropIfExists('ptar_plantas');
    }
};
