<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Código numérico de la carpeta/consolidado histórico de insumos
     * (23 Puerto Morelos … 31 Kohunlich, 00 Edificio Zonal Este). Se llena
     * desde EstacionSeeder.
     */
    public function up(): void
    {
        Schema::table('estaciones', function (Blueprint $table) {
            $table->string('codigo', 2)->nullable()->unique()->after('nombre');
        });
    }

    public function down(): void
    {
        Schema::table('estaciones', function (Blueprint $table) {
            $table->dropUnique(['codigo']);
            $table->dropColumn('codigo');
        });
    }
};
