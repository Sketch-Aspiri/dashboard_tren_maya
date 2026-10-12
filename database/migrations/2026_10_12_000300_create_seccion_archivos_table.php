<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Documentos subidos a una sección (repositorio) o adjuntos a una fila.
     * El archivo vive en el disco privado bajo una ruta generada por el
     * servidor (nunca el nombre del cliente). texto_extraido alimenta la
     * búsqueda de documentos y el asistente (índice FULLTEXT).
     * La llave foránea a seccion_filas se agrega en la migración de filas
     * (dependencia circular filas -> importaciones -> archivos).
     */
    public function up(): void
    {
        Schema::create('seccion_archivos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seccion_id')->constrained('secciones')->cascadeOnDelete();
            $table->unsignedBigInteger('seccion_fila_id')->nullable()->index();
            $table->string('nombre_original', 255);
            $table->string('ruta', 255);
            $table->string('mime', 150);
            $table->string('extension', 10);
            $table->unsignedBigInteger('tamano');
            $table->longText('texto_extraido')->nullable();
            $table->foreignId('subido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->fullText(['nombre_original', 'texto_extraido']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seccion_archivos');
    }
};
