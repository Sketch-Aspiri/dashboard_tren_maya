<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventario de cada estación a partir de sus resguardos (herramienta, equipo
 * de protección y consumibles entregados) y datos de contacto de proveedores.
 * `clave` es la descripción normalizada y sirve para comparar lo que la
 * estación solicita con lo que ya tiene.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventario_estaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estacion_id')->constrained('estaciones')->cascadeOnDelete();
            $table->string('tipo', 12); // HERRAMIENTA | EPP | CONSUMIBLE
            $table->string('clave', 190);
            $table->string('descripcion', 255);
            $table->string('unidad', 60)->nullable();
            $table->decimal('cantidad', 10, 2)->default(0);
            $table->string('primer_mes', 7)->nullable();
            $table->string('ultimo_mes', 7)->nullable();
            $table->string('partida_presupuestal', 10)->nullable();
            $table->string('fuente', 30)->default('resguardos');
            $table->timestamps();

            $table->unique(['estacion_id', 'tipo', 'clave']);
            $table->index(['estacion_id', 'tipo']);
        });

        Schema::table('proveedores', function (Blueprint $table) {
            $table->string('giro', 80)->nullable()->after('rfc');
            $table->string('ubicacion', 80)->nullable()->after('giro');
        });
    }

    public function down(): void
    {
        Schema::table('proveedores', function (Blueprint $table) {
            $table->dropColumn(['giro', 'ubicacion']);
        });

        Schema::dropIfExists('inventario_estaciones');
    }
};
