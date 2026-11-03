<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cuentas de nube conectadas por OAuth (solo lectura). Los tokens se
     * guardan cifrados (cast `encrypted`), por eso son columnas de texto.
     */
    public function up(): void
    {
        Schema::create('nube_cuentas', function (Blueprint $table) {
            $table->id();
            $table->string('proveedor', 20);
            $table->string('cuenta_email', 255);
            $table->string('cuenta_nombre', 255)->nullable();
            $table->string('tenant_id', 100)->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expira_en')->nullable();
            $table->text('scopes')->nullable();
            $table->string('estado', 30)->default('activa');
            $table->string('ultimo_error', 500)->nullable();
            $table->foreignId('conectada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['proveedor', 'cuenta_email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nube_cuentas');
    }
};
