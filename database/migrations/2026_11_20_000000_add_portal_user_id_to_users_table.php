<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Identidad común con el portal (perfil único). Nula en las cuentas que aún no se emparejan.
            $table->uuid('portal_user_id')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['portal_user_id']);
            $table->dropColumn('portal_user_id');
        });
    }
};
