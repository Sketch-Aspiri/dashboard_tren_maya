<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Secciones dinámicas del menú: cada una tiene su propia tabla
     * configurable (seccion_columnas / seccion_filas) y su repositorio de
     * documentos (seccion_archivos). Soft deletes: eliminar una sección
     * desde la pantalla nunca borra físicamente su información.
     */
    public function up(): void
    {
        Schema::create('secciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_grupo_id')->constrained('menu_grupos')->restrictOnDelete();
            $table->string('nombre', 160);
            $table->string('slug', 180)->unique();
            $table->text('descripcion')->nullable();
            $table->unsignedInteger('orden')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['menu_grupo_id', 'orden']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secciones');
    }
};
