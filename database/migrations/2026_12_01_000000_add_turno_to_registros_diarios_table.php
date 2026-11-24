<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Turno de la persona ese día (primer/segundo), como la columna Turno del Excel de control. Los registros
     * existentes quedan en primer turno.
     */
    public function up(): void
    {
        Schema::table('registros_diarios', function (Blueprint $table) {
            $table->string('turno', 10)->default('primer')->after('estatus');
        });
    }

    public function down(): void
    {
        Schema::table('registros_diarios', function (Blueprint $table) {
            $table->dropColumn('turno');
        });
    }
};
