<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const NOMBRE_POR_DEFECTO = 'Principal';

    /**
     * Una sección pasa a tener varias tablas (hojas de un libro, p. ej.).
     * Columnas y filas cuelgan de su tabla (seccion_tabla_id); seccion_id se
     * conserva denormalizado para el asistente, el dashboard y los
     * documentos. Los datos existentes se asignan a una tabla "Principal"
     * por cada sección que ya tenía columnas o filas.
     */
    public function up(): void
    {
        Schema::create('seccion_tablas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seccion_id')->constrained('secciones')->cascadeOnDelete();
            $table->string('nombre', 160);
            $table->unsignedInteger('orden')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['seccion_id', 'deleted_at', 'orden']);
        });

        Schema::table('seccion_columnas', function (Blueprint $table) {
            $table->foreignId('seccion_tabla_id')->nullable()->after('seccion_id')->constrained('seccion_tablas')->cascadeOnDelete();
        });

        Schema::table('seccion_filas', function (Blueprint $table) {
            $table->foreignId('seccion_tabla_id')->nullable()->after('seccion_id')->constrained('seccion_tablas')->cascadeOnDelete();
        });

        Schema::table('seccion_importaciones', function (Blueprint $table) {
            $table->foreignId('seccion_tabla_id')->nullable()->after('seccion_id')->constrained('seccion_tablas')->nullOnDelete();
        });

        $this->asignarTablaPorDefecto();

        Schema::table('seccion_columnas', function (Blueprint $table) {
            $table->unsignedBigInteger('seccion_tabla_id')->nullable(false)->change();
            // La FK de seccion_id usa el índice único viejo: se le da uno propio antes de quitarlo.
            $table->unique(['seccion_tabla_id', 'clave']);
            $table->index('seccion_id');
            $table->dropUnique(['seccion_id', 'clave']);
        });

        Schema::table('seccion_filas', function (Blueprint $table) {
            $table->unsignedBigInteger('seccion_tabla_id')->nullable(false)->change();
            $table->index(['seccion_tabla_id', 'deleted_at']);
        });
    }

    /**
     * Revertir solo es posible si las claves de columna no se repiten entre
     * las tablas de una misma sección (el unique vuelve a ser por sección).
     */
    public function down(): void
    {
        Schema::table('seccion_columnas', function (Blueprint $table) {
            $table->unique(['seccion_id', 'clave']);
            $table->dropForeign(['seccion_tabla_id']);
            $table->dropUnique(['seccion_tabla_id', 'clave']);
            $table->dropIndex(['seccion_id']);
            $table->dropColumn('seccion_tabla_id');
        });

        Schema::table('seccion_filas', function (Blueprint $table) {
            $table->dropForeign(['seccion_tabla_id']);
            $table->dropIndex(['seccion_tabla_id', 'deleted_at']);
            $table->dropColumn('seccion_tabla_id');
        });

        Schema::table('seccion_importaciones', function (Blueprint $table) {
            $table->dropForeign(['seccion_tabla_id']);
            $table->dropColumn('seccion_tabla_id');
        });

        Schema::dropIfExists('seccion_tablas');
    }

    /**
     * Una tabla "Principal" por sección con columnas o filas (incluidas las
     * dadas de baja: sus filas siguen en la base) y todo lo existente a ella.
     */
    private function asignarTablaPorDefecto(): void
    {
        $ahora = now();

        $secciones = DB::table('seccion_columnas')->select('seccion_id')
            ->union(DB::table('seccion_filas')->select('seccion_id'))
            ->pluck('seccion_id');

        foreach ($secciones as $seccionId) {
            $tablaId = DB::table('seccion_tablas')->insertGetId([
                'seccion_id' => $seccionId,
                'nombre' => self::NOMBRE_POR_DEFECTO,
                'orden' => 1,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);

            DB::table('seccion_columnas')->where('seccion_id', $seccionId)->update(['seccion_tabla_id' => $tablaId]);
            DB::table('seccion_filas')->where('seccion_id', $seccionId)->update(['seccion_tabla_id' => $tablaId]);
        }
    }
};
