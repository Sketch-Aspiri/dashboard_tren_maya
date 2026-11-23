<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agua_registros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estacion_id')->constrained('estaciones')->cascadeOnDelete();
            $table->unsignedSmallInteger('anio');
            $table->unsignedTinyInteger('mes');
            $table->string('estado', 12)->default('borrador');
            // Programa del mes (lo define la Zona; cada mes nuevo hereda el anterior).
            $table->string('frecuencia', 12)->default('quincenal');
            $table->unsignedSmallInteger('consumo_mensual')->default(0);
            $table->decimal('costo_unitario', 8, 2)->default(0);
            $table->unsignedSmallInteger('garrafones_propios')->default(0);
            $table->unsignedSmallInteger('garrafones_comodato')->default(0);
            $table->string('referencia', 60)->nullable();
            $table->string('dia_entrega', 60)->nullable();
            $table->timestamp('enviada_at')->nullable();
            $table->foreignId('revisada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revisada_at')->nullable();
            $table->text('comentario_zona')->nullable();
            $table->timestamps();

            $table->unique(['estacion_id', 'anio', 'mes']);
            $table->index(['anio', 'mes', 'estado']);
        });

        Schema::create('agua_compras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registro_id')->constrained('agua_registros')->cascadeOnDelete();
            $table->date('fecha')->nullable();
            $table->unsignedSmallInteger('garrafones');
            $table->decimal('costo_unitario', 8, 2);
            $table->decimal('monto', 10, 2);
            $table->string('comprobante_ruta')->nullable();
            $table->string('comprobante_nombre')->nullable();
            $table->string('nota', 255)->nullable();
            $table->string('origen', 12)->default('captura');
            $table->foreignId('capturo_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agua_compras');
        Schema::dropIfExists('agua_registros');
    }
};
