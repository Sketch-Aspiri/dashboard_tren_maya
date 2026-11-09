<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catálogo homologado de insumos (descripción, unidad, código, último
     * precio, partida presupuestal y proveedor). Lo mantiene la Zona; la
     * estación lo usa solo como autocompletado al capturar.
     */
    public function up(): void
    {
        Schema::create('catalogo_insumos', function (Blueprint $table) {
            $table->id();
            $table->string('descripcion')->unique();
            $table->string('unidad', 30);
            $table->string('codigo', 50)->nullable();
            $table->decimal('ultimo_precio', 12, 2)->nullable();
            $table->string('partida_presupuestal', 10)->nullable();
            $table->string('proveedor')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalogo_insumos');
    }
};
