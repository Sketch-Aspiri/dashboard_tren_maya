<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * "Agenda Zona Oriente" -> Rol de vacaciones: una fila por persona y año
     * (ANEXO A de vacacionistas). `empleado_id` enlaza con el directorio de
     * Personal cuando el no. de empleado coincide; ninguna de las dos
     * relaciones borra en cascada al empleado ni a la estación.
     */
    public function up(): void
    {
        Schema::create('vacacionistas', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('anio');
            $table->string('no_empleado');
            $table->string('nombre_completo');
            $table->string('denominacion_puesto')->nullable();
            $table->unsignedSmallInteger('dias_otorgados')->nullable();
            $table->foreignId('estacion_id')->nullable()->constrained('estaciones')->nullOnDelete();
            $table->foreignId('empleado_id')->nullable()->constrained('empleados')->nullOnDelete();
            $table->timestamps();

            $table->unique(['anio', 'no_empleado']);
            $table->index(['anio', 'estacion_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vacacionistas');
    }
};
