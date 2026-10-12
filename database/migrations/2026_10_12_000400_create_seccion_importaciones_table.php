<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cada documento con tabla(s) arrastrado a una sección genera una
     * importación: primero en "vista_previa" (tablas detectadas), luego
     * "aplicada" (con el registro de cambios para poder deshacerla) o
     * "deshecha".
     */
    public function up(): void
    {
        Schema::create('seccion_importaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seccion_id')->constrained('secciones')->cascadeOnDelete();
            $table->foreignId('seccion_archivo_id')->nullable()->constrained('seccion_archivos')->nullOnDelete();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('origen', 20);
            $table->string('estado', 20)->index();
            $table->string('modo', 20)->nullable();
            $table->json('tablas');
            $table->json('resumen')->nullable();
            $table->json('cambios')->nullable();
            $table->timestamp('aplicada_at')->nullable();
            $table->timestamp('deshecha_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seccion_importaciones');
    }
};
