<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Oficios de comisión (Word, PDF o imagen) que cada estación sube y que se
        // anexan al final del control de asistencia de la estación y al de la zona.
        Schema::create('oficios_comision', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estacion_id')->constrained('estaciones')->cascadeOnDelete();
            $table->string('nombre_original', 200);
            $table->string('ruta', 255);
            $table->string('extension', 5);
            $table->unsignedInteger('tamano');
            // Se anexa mientras la fecha del control sea <= a esta; vacío = hasta que se quite.
            $table->date('vigente_hasta')->nullable();
            $table->foreignId('subido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['estacion_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oficios_comision');
    }
};
