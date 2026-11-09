<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cotizaciones de proveedores del fondo rotatorio (fase A, antes el Flujo
     * 2A de n8n). Cada renglón cotizado se vincula con una partida de las
     * solicitudes del mes; si cumple las reglas, el precio cotizado
     * reemplaza al estimado (que se conserva en precio_estimado).
     */
    public function up(): void
    {
        Schema::create('cotizaciones', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('anio');
            $table->unsignedTinyInteger('mes');
            $table->string('proveedor', 150);
            $table->string('folio', 50);
            $table->date('fecha')->nullable();
            $table->decimal('subtotal', 12, 2)->nullable();
            $table->decimal('iva', 12, 2)->nullable();
            $table->decimal('total', 12, 2)->nullable();
            $table->string('archivo_nombre')->nullable();
            $table->string('archivo_ruta')->nullable();
            $table->string('validacion', 20)->default('OK');
            $table->text('validacion_detalle')->nullable();
            $table->boolean('leida_con_ia')->default(false);
            $table->foreignId('creada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['proveedor', 'folio']);
            $table->index(['anio', 'mes']);
        });

        Schema::create('cotizacion_renglones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cotizacion_id')->constrained('cotizaciones')->cascadeOnDelete();
            $table->foreignId('solicitud_insumo_partida_id')->nullable()->constrained('solicitud_insumo_partidas')->nullOnDelete();
            $table->unsignedSmallInteger('numero');
            $table->string('clave', 50)->nullable();
            $table->string('descripcion');
            $table->decimal('cantidad', 10, 2);
            $table->decimal('precio_unitario', 12, 2)->nullable();
            $table->decimal('importe', 12, 2)->nullable();
            $table->string('coincidencia', 20)->default('mismo');
            $table->string('estatus', 20)->default('sin_vincular');
            $table->string('motivo', 40)->nullable();
            $table->string('motivo_detalle')->nullable();
            $table->timestamps();
        });

        Schema::table('solicitud_insumo_partidas', function (Blueprint $table) {
            $table->decimal('precio_estimado', 12, 2)->nullable()->after('precio_unitario');
        });
    }

    public function down(): void
    {
        Schema::table('solicitud_insumo_partidas', function (Blueprint $table) {
            $table->dropColumn('precio_estimado');
        });

        Schema::dropIfExists('cotizacion_renglones');
        Schema::dropIfExists('cotizaciones');
    }
};
