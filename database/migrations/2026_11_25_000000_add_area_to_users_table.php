<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Área (equipo) de la cuenta de estación: rh, rm, mantenimiento... Solo aplica al rol Estación.
            $table->string('area', 32)->nullable()->after('estacion_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('area');
        });
    }
};
