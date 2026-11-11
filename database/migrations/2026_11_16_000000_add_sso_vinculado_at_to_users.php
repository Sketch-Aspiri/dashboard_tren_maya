<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inicio de sesión único con Tickets: la cuenta queda vinculada la primera vez
 * que su dueño inicia sesión aquí con contraseña llegando desde Tickets con el
 * mismo correo (prueba que controla las dos cuentas). Sin vínculo, el correo
 * por sí solo nunca abre una sesión.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('sso_vinculado_at')->nullable()->after('google2fa_enabled_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('sso_vinculado_at');
        });
    }
};
