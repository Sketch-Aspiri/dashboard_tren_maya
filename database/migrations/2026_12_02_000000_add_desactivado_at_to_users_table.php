<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Baja desde el portal: la cuenta se conserva (datos e historial) pero no puede entrar.
            $table->timestamp('desactivado_at')->nullable()->after('portal_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('desactivado_at');
        });
    }
};
