<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Enlaces (direcciones web) guardados en una sección: título, URL http(s)
     * y una nota opcional.
     */
    public function up(): void
    {
        Schema::create('seccion_enlaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seccion_id')->constrained('secciones')->cascadeOnDelete();
            $table->string('titulo', 160);
            $table->string('url', 2000);
            $table->string('nota', 500)->nullable();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['seccion_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seccion_enlaces');
    }
};
