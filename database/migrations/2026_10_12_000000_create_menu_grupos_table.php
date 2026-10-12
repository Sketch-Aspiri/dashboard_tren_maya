<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Grupos del menú principal ("RR.HH.", "Recursos materiales", ...).
     * Antes vivían fijos en config/navegacion.php; ahora el administrador
     * puede crearlos y renombrarlos desde "Administrar menú". MenuSeeder
     * carga los grupos iniciales del PPT "Dashboard Tren Maya".
     */
    public function up(): void
    {
        Schema::create('menu_grupos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 120);
            $table->string('slug', 140)->unique();
            $table->unsignedInteger('orden')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_grupos');
    }
};
