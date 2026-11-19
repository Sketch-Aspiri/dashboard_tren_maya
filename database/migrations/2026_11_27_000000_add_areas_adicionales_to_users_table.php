<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Equipos adicionales de una cuenta Estación (el principal sigue en `area`, que es el que viaja al Portal y a Tickets).
        Schema::table('users', function (Blueprint $table) {
            $table->json('areas_adicionales')->nullable()->after('area');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('areas_adicionales');
        });
    }
};
