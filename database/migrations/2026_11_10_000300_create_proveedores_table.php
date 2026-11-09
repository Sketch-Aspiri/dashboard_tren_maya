<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Proveedores del fondo rotatorio y su tasa de IVA (8 % o 16 %). La tasa
     * la asigna la administración; null = sin definir (se aplica la tasa por
     * defecto y el CONTROL_FILTRO lo avisa). Las partidas guardan el nombre
     * del proveedor como texto y se relacionan por nombre.
     */
    public function up(): void
    {
        Schema::create('proveedores', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 150)->unique();
            $table->unsignedTinyInteger('iva')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proveedores');
    }
};
