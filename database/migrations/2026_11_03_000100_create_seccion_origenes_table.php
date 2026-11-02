<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Origen de nube: lo que una sección tiene vinculado (hoy un archivo por
     * enlace público; después carpetas con cuenta OAuth). Reemplaza las
     * columnas `origen_*` de `seccion_archivos`.
     *
     * Solo aditiva: las columnas viejas NO se tocan (quedan sin uso) y se
     * borrarán en una migración posterior, ya verificado en producción.
     */
    public function up(): void
    {
        Schema::create('seccion_origenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seccion_id')->constrained('secciones')->cascadeOnDelete();
            $table->string('tipo', 10)->default('archivo');
            $table->string('proveedor', 20);
            $table->string('enlace_original', 2000);
            $table->string('nombre', 250)->nullable();
            $table->boolean('alimenta_tablas')->default(true);
            $table->string('estado', 20)->default('activo');
            $table->timestamp('sincronizado_en')->nullable();
            $table->string('sincronizacion_error', 500)->nullable();
            $table->json('resumen')->nullable();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['estado', 'deleted_at']);
        });

        Schema::table('seccion_archivos', function (Blueprint $table) {
            $table->foreignId('seccion_origen_id')->nullable()->after('seccion_fila_id')
                ->constrained('seccion_origenes')->nullOnDelete();
            $table->char('contenido_hash', 64)->nullable()->after('texto_extraido');
        });

        $this->convertirVinculos();
    }

    /**
     * Convierte cada documento vigente (no borrado) con `origen_url` en un
     * origen de tipo archivo. Copia los datos (no mueve ni borra nada) y es
     * idempotente.
     */
    public function convertirVinculos(): void
    {
        DB::table('seccion_archivos')
            ->whereNotNull('origen_url')
            ->whereNull('seccion_origen_id')
            ->whereNull('deleted_at')
            ->chunkById(200, function ($archivos): void {
                foreach ($archivos as $archivo) {
                    DB::transaction(function () use ($archivo): void {
                        $origenId = DB::table('seccion_origenes')->insertGetId([
                            'seccion_id' => $archivo->seccion_id,
                            'tipo' => 'archivo',
                            'proveedor' => $archivo->origen_proveedor ?? 'desconocido',
                            'enlace_original' => $archivo->origen_url,
                            'nombre' => $archivo->nombre_original,
                            'estado' => $archivo->sincronizacion_activa ? 'activo' : 'pausado',
                            'sincronizado_en' => $archivo->sincronizado_en,
                            'sincronizacion_error' => $archivo->sincronizacion_error,
                            'creado_por' => $archivo->subido_por,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        DB::table('seccion_archivos')->where('id', $archivo->id)->update([
                            'seccion_origen_id' => $origenId,
                            'contenido_hash' => $archivo->origen_hash,
                        ]);
                    });
                }
            });
    }

    /**
     * Devuelve el estado a las columnas `origen_*` antes de quitar lo nuevo,
     * para que un rollback no pierda los vínculos hechos mientras estuvo.
     */
    public function down(): void
    {
        // Lo desvinculado mientras estuvo la migración no debe reaparecer: se limpian
        // las columnas viejas y solo se restauran los vínculos que siguen vivos.
        DB::table('seccion_archivos')->update([
            'origen_url' => null,
            'origen_proveedor' => null,
            'origen_hash' => null,
            'sincronizacion_activa' => false,
            'sincronizado_en' => null,
            'sincronizacion_error' => null,
        ]);

        DB::table('seccion_archivos')
            ->join('seccion_origenes', 'seccion_origenes.id', '=', 'seccion_archivos.seccion_origen_id')
            ->whereNull('seccion_origenes.deleted_at')
            ->select('seccion_archivos.id', 'seccion_archivos.contenido_hash', 'seccion_origenes.proveedor',
                'seccion_origenes.enlace_original', 'seccion_origenes.estado', 'seccion_origenes.sincronizado_en',
                'seccion_origenes.sincronizacion_error')
            ->orderBy('seccion_archivos.id')
            ->each(function (object $fila): void {
                DB::table('seccion_archivos')->where('id', $fila->id)->update([
                    'origen_url' => $fila->enlace_original,
                    'origen_proveedor' => $fila->proveedor,
                    'origen_hash' => $fila->contenido_hash,
                    'sincronizacion_activa' => $fila->estado === 'activo',
                    'sincronizado_en' => $fila->sincronizado_en,
                    'sincronizacion_error' => $fila->sincronizacion_error,
                ]);
            });

        Schema::table('seccion_archivos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('seccion_origen_id');
            $table->dropColumn('contenido_hash');
        });

        Schema::dropIfExists('seccion_origenes');
    }
};
