<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Facturas CFDI del fondo rotatorio (fase B, antes el Flujo 2B de n8n).
     * Se cargan desde el XML (UUID, RFC, conceptos e impuestos ya vienen
     * estructurados), se vinculan con su cotización y con las partidas del
     * mes, y de la comparación salen los hallazgos de la comprobación.
     * También se agrega el RFC a los proveedores para reconocer al emisor.
     */
    public function up(): void
    {
        Schema::table('proveedores', function (Blueprint $table) {
            $table->string('rfc', 13)->nullable()->after('nombre');
        });

        Schema::create('facturas', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('anio');
            $table->unsignedTinyInteger('mes');
            $table->string('uuid', 36)->unique();
            $table->string('serie', 25)->nullable();
            $table->string('folio', 40)->nullable();
            $table->dateTime('fecha');
            $table->dateTime('fecha_timbrado')->nullable();
            $table->string('emisor_rfc', 13);
            $table->string('emisor_nombre')->nullable();
            $table->string('receptor_rfc', 13);
            $table->string('receptor_nombre')->nullable();
            $table->string('tipo_comprobante', 1)->default('I');
            $table->string('moneda', 5)->default('MXN');
            $table->string('metodo_pago', 5)->nullable();
            $table->decimal('subtotal', 12, 2);
            $table->decimal('descuento', 12, 2)->default(0);
            $table->decimal('iva', 12, 2)->default(0);
            $table->decimal('isr_retenido', 12, 2)->default(0);
            $table->decimal('otros_retenidos', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->string('referencia_cot', 20)->nullable();
            $table->foreignId('cotizacion_id')->nullable()->constrained('cotizaciones')->nullOnDelete();
            $table->string('vinculo_cotizacion', 20)->nullable();
            $table->foreignId('estacion_id')->nullable()->constrained('estaciones')->nullOnDelete();
            $table->string('xml_ruta')->nullable();
            $table->string('xml_nombre')->nullable();
            $table->string('pdf_ruta')->nullable();
            $table->string('pdf_nombre')->nullable();
            $table->string('estado', 10)->default('REVISAR');
            $table->string('verificacion_sat', 20)->default('NO_VERIFICADO');
            $table->foreignId('creada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['anio', 'mes']);
        });

        Schema::create('factura_conceptos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('factura_id')->constrained('facturas')->cascadeOnDelete();
            $table->unsignedSmallInteger('numero');
            $table->string('clave_prod_serv', 10)->nullable();
            $table->string('no_identificacion', 60)->nullable();
            $table->string('unidad', 40)->nullable();
            $table->text('descripcion');
            $table->decimal('cantidad', 12, 4);
            $table->decimal('valor_unitario', 14, 4);
            $table->decimal('descuento', 12, 2)->default(0);
            $table->decimal('importe', 12, 2);
            $table->decimal('iva_tasa', 5, 2)->nullable();
            $table->decimal('iva_importe', 12, 2)->default(0);
            $table->foreignId('solicitud_insumo_partida_id')->nullable()->constrained('solicitud_insumo_partidas')->nullOnDelete();
            $table->foreignId('cotizacion_renglon_id')->nullable()->constrained('cotizacion_renglones')->nullOnDelete();
            $table->string('vinculo', 20)->nullable();
            $table->timestamps();
        });

        Schema::create('factura_hallazgos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('factura_id')->constrained('facturas')->cascadeOnDelete();
            $table->foreignId('factura_concepto_id')->nullable()->constrained('factura_conceptos')->cascadeOnDelete();
            $table->string('tipo', 10);
            $table->string('codigo', 40);
            $table->text('detalle')->nullable();
            $table->decimal('valor_cotizado', 14, 4)->nullable();
            $table->decimal('valor_facturado', 14, 4)->nullable();
            $table->decimal('diferencia', 14, 4)->nullable();
            $table->timestamps();

            $table->index(['factura_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('factura_hallazgos');
        Schema::dropIfExists('factura_conceptos');
        Schema::dropIfExists('facturas');

        Schema::table('proveedores', function (Blueprint $table) {
            $table->dropColumn('rfc');
        });
    }
};
