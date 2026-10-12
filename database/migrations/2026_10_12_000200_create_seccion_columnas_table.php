<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Columnas de la tabla de una sección. "clave" es el nombre interno
     * (snake_case, estable aunque se renombre la columna) con el que el
     * valor se guarda en seccion_filas.datos.
     */
    public function up(): void
    {
        Schema::create('seccion_columnas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seccion_id')->constrained('secciones')->cascadeOnDelete();
            $table->string('nombre', 120);
            $table->string('clave', 64);
            $table->string('tipo', 20);
            $table->json('opciones')->nullable();
            $table->unsignedInteger('orden')->default(0);
            $table->timestamps();

            $table->unique(['seccion_id', 'clave']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seccion_columnas');
    }
};
