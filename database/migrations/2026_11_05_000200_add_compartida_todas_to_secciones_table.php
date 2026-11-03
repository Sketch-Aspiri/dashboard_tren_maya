<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Compartida con todas las estaciones". Por defecto false: toda sección
 * existente sigue siendo privada de la zona hasta que se apruebe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('secciones', function (Blueprint $table) {
            $table->boolean('compartida_todas')->default(false)->after('solo_documentos');
        });
    }

    public function down(): void
    {
        Schema::table('secciones', function (Blueprint $table) {
            $table->dropColumn('compartida_todas');
        });
    }
};
