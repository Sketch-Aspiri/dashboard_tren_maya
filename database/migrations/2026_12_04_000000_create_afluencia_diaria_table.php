<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('afluencia_diaria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estacion_id')->constrained('estaciones')->restrictOnDelete();
            $table->date('fecha');
            // Nulo = sin captura (no es cero): el Excel de origen deja vacíos los días sin dato.
            $table->unsignedInteger('abordan')->nullable();
            $table->unsignedInteger('boletos')->nullable();
            $table->foreignId('capturado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['estacion_id', 'fecha']);
            $table->index('fecha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('afluencia_diaria');
    }
};
