<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Secciones que el administrador aprueba para cada estación. Aprobar una
 * sección aprueba también sus descendientes (ver VisibilidadSecciones).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seccion_estacion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seccion_id')->constrained('secciones')->cascadeOnDelete();
            $table->foreignId('estacion_id')->constrained('estaciones')->cascadeOnDelete();
            $table->foreignId('aprobado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['seccion_id', 'estacion_id']);
            $table->index('estacion_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seccion_estacion');
    }
};
