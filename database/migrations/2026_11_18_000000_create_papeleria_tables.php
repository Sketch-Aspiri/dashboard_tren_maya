<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Solicitud mensual de papelería: una por estación y mes (borrador ->
     * enviada -> observada | aprobada), con su catálogo de artículos.
     */
    public function up(): void
    {
        Schema::create('catalogo_papeleria', function (Blueprint $table) {
            $table->id();
            $table->string('descripcion')->unique();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('solicitudes_papeleria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estacion_id')->constrained('estaciones')->restrictOnDelete();
            $table->unsignedSmallInteger('anio');
            $table->unsignedTinyInteger('mes');
            $table->string('estado', 20)->default('borrador');
            $table->timestamp('enviada_at')->nullable();
            $table->foreignId('revisada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revisada_at')->nullable();
            $table->text('comentario_zona')->nullable();
            $table->timestamps();

            $table->unique(['estacion_id', 'anio', 'mes']);
            $table->index(['anio', 'mes', 'estado']);
        });

        Schema::create('solicitud_papeleria_partidas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('solicitud_papeleria_id')->constrained('solicitudes_papeleria')->cascadeOnDelete();
            $table->unsignedSmallInteger('numero');
            $table->foreignId('catalogo_papeleria_id')->nullable()->constrained('catalogo_papeleria')->nullOnDelete();
            $table->string('articulo');
            $table->string('unidad', 30);
            $table->decimal('cantidad', 10, 2);
            $table->text('comentarios')->nullable();
            $table->timestamps();

            $table->unique(['solicitud_papeleria_id', 'numero'], 'partidas_papeleria_numero_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitud_papeleria_partidas');
        Schema::dropIfExists('solicitudes_papeleria');
        Schema::dropIfExists('catalogo_papeleria');
    }
};
