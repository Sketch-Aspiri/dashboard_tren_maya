<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Base para las tablas espejo (tanda 3 del plan de nube): una tabla con
     * `seccion_origen_id` es de solo lectura y el mapeo guarda cómo se lee
     * cada hoja. Aún nada la usa.
     */
    public function up(): void
    {
        Schema::table('seccion_tablas', function (Blueprint $table) {
            $table->foreignId('seccion_origen_id')->nullable()->after('seccion_id')
                ->constrained('seccion_origenes')->nullOnDelete();
        });

        Schema::create('seccion_mapeos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seccion_origen_id')->constrained('seccion_origenes')->cascadeOnDelete();
            $table->foreignId('seccion_archivo_id')->constrained('seccion_archivos')->cascadeOnDelete();
            $table->string('hoja', 160);
            // Sin FK a propósito: una FK hacia seccion_tablas impediría revertir la
            // migración antigua create_seccion_tablas (MigracionTablasTest la revierte).
            // La integridad la mantiene la aplicación (tanda 3); se indexa para consultar.
            $table->unsignedBigInteger('seccion_tabla_id')->nullable()->index();
            $table->unsignedSmallInteger('fila_encabezado')->default(1);
            $table->json('columnas')->nullable();
            $table->string('llave_clave', 100)->nullable();
            $table->foreignId('confirmado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmado_en')->nullable();
            $table->timestamps();

            $table->unique(['seccion_archivo_id', 'hoja']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seccion_mapeos');

        Schema::table('seccion_tablas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('seccion_origen_id');
        });
    }
};
