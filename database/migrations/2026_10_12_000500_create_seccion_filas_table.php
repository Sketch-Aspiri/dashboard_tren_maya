<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Filas de la tabla de una sección. Los valores viven en "datos" (JSON
     * indexado por seccion_columnas.clave). texto_busqueda es una copia en
     * minúsculas y sin acentos de todos los valores, para el buscador.
     * seccion_importacion_id marca las filas CREADAS por una importación
     * (deshacerla las elimina).
     */
    public function up(): void
    {
        Schema::create('seccion_filas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seccion_id')->constrained('secciones')->cascadeOnDelete();
            $table->json('datos');
            $table->mediumText('texto_busqueda')->nullable();
            $table->foreignId('seccion_importacion_id')->nullable()->constrained('seccion_importaciones')->nullOnDelete();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['seccion_id', 'deleted_at']);
        });

        Schema::table('seccion_archivos', function (Blueprint $table) {
            $table->foreign('seccion_fila_id')->references('id')->on('seccion_filas')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('seccion_archivos', function (Blueprint $table) {
            $table->dropForeign(['seccion_fila_id']);
        });

        Schema::dropIfExists('seccion_filas');
    }
};
