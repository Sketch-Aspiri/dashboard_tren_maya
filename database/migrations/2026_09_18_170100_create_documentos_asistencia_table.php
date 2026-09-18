<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per generated attendance oficio (station or zone) per fecha.
     * Regenerating the same oficio updates this row (same folio) rather
     * than adding another, so unique(tipo, estacion_id, fecha) holds for
     * station oficios. Zone oficios have estacion_id NULL, which MySQL's
     * unique index does not constrain — AsistenciaDocumentoService
     * serializes those with a transaction + lock instead.
     */
    public function up(): void
    {
        Schema::create('documentos_asistencia', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 16);
            $table->foreignId('estacion_id')->nullable()->constrained('estaciones')->restrictOnDelete();
            $table->date('fecha');
            $table->string('numero_oficio', 80)->unique();
            $table->string('firmante_cargo', 150);
            $table->string('firmante_nombre', 150);
            $table->boolean('es_suplencia')->default(false);
            $table->string('docx_path');
            $table->string('pdf_path');
            $table->foreignId('generado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tipo', 'estacion_id', 'fecha']);
            $table->index(['tipo', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentos_asistencia');
    }
};
