<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Client ID / Client Secret de las apps OAuth de cada nube, cargados por
     * el Administrador desde la aplicación. El secreto se guarda cifrado
     * (cast `encrypted` del modelo). Si no hay fila, se usa el .env.
     */
    public function up(): void
    {
        Schema::create('nube_credenciales', function (Blueprint $table) {
            $table->id();
            $table->string('proveedor', 20)->unique();
            $table->string('client_id', 255);
            $table->text('client_secret');
            $table->string('tenant', 100)->nullable();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nube_credenciales');
    }
};
