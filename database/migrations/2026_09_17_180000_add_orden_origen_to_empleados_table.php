<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Preserves the original row position from the source Excel sheet
     * ("Base de Datos Zona Oriente"), so the Agenda Zona Oriente listing
     * can display employees in the same order the Jefe de Zona expects
     * (the order they appear in the spreadsheet), instead of an arbitrary
     * default like alphabetical or insertion order.
     */
    public function up(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            $table->unsignedInteger('orden_origen')->nullable()->index()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            $table->dropColumn('orden_origen');
        });
    }
};
