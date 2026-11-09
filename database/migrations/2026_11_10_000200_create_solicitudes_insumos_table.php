<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Solicitud mensual de insumos del fondo rotatorio: una por estación y
     * mes. Estados: borrador -> enviada -> (observada | aprobada).
     */
    public function up(): void
    {
        Schema::create('solicitudes_insumos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estacion_id')->constrained('estaciones')->restrictOnDelete();
            $table->unsignedSmallInteger('anio');
            $table->unsignedTinyInteger('mes');
            $table->string('tipo_servicio', 20)->default('1er escalón');
            $table->string('lugar_trabajo')->nullable();
            $table->text('justificacion_general')->nullable();
            $table->string('estado', 20)->default('borrador');
            $table->string('solicitante_nombre')->nullable();
            $table->string('solicitante_puesto')->nullable();
            $table->timestamp('enviada_at')->nullable();
            $table->foreignId('revisada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revisada_at')->nullable();
            $table->text('comentario_zona')->nullable();
            $table->timestamps();

            $table->unique(['estacion_id', 'anio', 'mes']);
            $table->index(['anio', 'mes', 'estado']);
        });

        Schema::create('solicitud_insumo_partidas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('solicitud_insumo_id')->constrained('solicitudes_insumos')->cascadeOnDelete();
            $table->unsignedSmallInteger('numero');
            $table->decimal('cantidad', 10, 2);
            $table->string('unidad', 30);
            $table->string('descripcion');
            $table->text('justificacion')->nullable();
            $table->foreignId('catalogo_insumo_id')->nullable()->constrained('catalogo_insumos')->nullOnDelete();
            // Capturados por la Zona al revisar.
            $table->string('codigo', 50)->nullable();
            $table->decimal('precio_unitario', 12, 2)->nullable();
            $table->string('partida_presupuestal', 10)->nullable();
            $table->string('proveedor')->nullable();
            $table->timestamps();

            $table->unique(['solicitud_insumo_id', 'numero']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitud_insumo_partidas');
        Schema::dropIfExists('solicitudes_insumos');
    }
};
