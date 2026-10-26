<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Subsecciones tipo carpetas: una sección puede colgar de otra (p. ej.
     * Estación > Persona). La subsección siempre conserva el grupo del menú
     * de su raíz; ese invariante lo mantiene MenuService.
     */
    public function up(): void
    {
        Schema::table('secciones', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('menu_grupo_id')
                ->constrained('secciones')->restrictOnDelete();

            $table->index(['menu_grupo_id', 'parent_id', 'orden']);
        });
    }

    public function down(): void
    {
        Schema::table('secciones', function (Blueprint $table) {
            $table->dropIndex(['menu_grupo_id', 'parent_id', 'orden']);
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
