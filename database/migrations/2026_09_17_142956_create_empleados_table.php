<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * "Agenda Zona Oriente" personnel directory — real data module sourced
     * from the Jefe de Zona's roster (see
     * app/Console/Commands/ImportAgendaZonaOrienteCommand.php). Contains
     * PII (CURP, RFC, NSS, domicilio, tipo de sangre, alergias, contacto
     * de emergencia) — this table is only ever reachable through the
     * VPN + 2FA gated dashboard, never a public endpoint.
     */
    public function up(): void
    {
        Schema::create('empleados', function (Blueprint $table) {
            $table->id();

            // Identificación / puesto
            $table->string('no_empleado')->nullable()->unique();
            $table->string('estatus')->default('activo');
            $table->string('estacion_codigo')->nullable();
            $table->string('plaza_actual')->nullable();
            $table->string('nombre_completo')->nullable();
            $table->string('puesto')->nullable();
            $table->string('nivel_plaza')->nullable();
            $table->string('ultimo_grado_estudios')->nullable();
            $table->string('titulo')->nullable();
            $table->string('cedula')->nullable();
            $table->date('fecha_ingreso')->nullable();

            // Contacto
            $table->string('telefono')->nullable();
            $table->string('correo')->nullable();

            // Datos personales
            $table->string('tipo_sangre')->nullable();
            $table->text('alergias')->nullable();
            $table->date('fecha_nacimiento')->nullable();
            $table->string('lugar_nacimiento')->nullable();
            $table->string('estado_civil')->nullable();
            $table->string('curp', 18)->nullable();
            $table->string('rfc', 13)->nullable();
            $table->string('nss', 11)->nullable();

            // Domicilio
            $table->text('domicilio')->nullable();

            // Contacto de emergencia
            $table->string('contacto_emergencia_nombre')->nullable();
            $table->string('contacto_emergencia_telefono')->nullable();

            $table->text('desempeno')->nullable();

            $table->timestamps();

            $table->index('estacion_codigo');
            $table->index('plaza_actual');
            $table->index('nombre_completo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('empleados');
    }
};
