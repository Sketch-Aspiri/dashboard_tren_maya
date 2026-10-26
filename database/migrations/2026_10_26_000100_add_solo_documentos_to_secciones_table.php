<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Secciones que solo guardan documentos: se oculta la parte de tabla
     * (los datos ya capturados se conservan por si se vuelve a activar).
     */
    public function up(): void
    {
        Schema::table('secciones', function (Blueprint $table) {
            $table->boolean('solo_documentos')->default(false)->after('is_visible');
        });
    }

    public function down(): void
    {
        Schema::table('secciones', function (Blueprint $table) {
            $table->dropColumn('solo_documentos');
        });
    }
};
