<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vínculo de un documento con un enlace compartido de la nube (Drive,
     * OneDrive, Dropbox). El comando secciones:sincronizar-nube lo vuelve a
     * descargar y, si el contenido cambió (hash), reemplaza el archivo.
     */
    public function up(): void
    {
        Schema::table('seccion_archivos', function (Blueprint $table) {
            $table->string('origen_url', 2000)->nullable()->after('texto_extraido');
            $table->string('origen_proveedor', 20)->nullable()->after('origen_url');
            $table->char('origen_hash', 64)->nullable()->after('origen_proveedor');
            $table->boolean('sincronizacion_activa')->default(false)->after('origen_hash');
            $table->timestamp('sincronizado_en')->nullable()->after('sincronizacion_activa');
            $table->string('sincronizacion_error', 500)->nullable()->after('sincronizado_en');

            $table->index('sincronizacion_activa');
        });
    }

    public function down(): void
    {
        Schema::table('seccion_archivos', function (Blueprint $table) {
            $table->dropIndex(['sincronizacion_activa']);
            $table->dropColumn([
                'origen_url',
                'origen_proveedor',
                'origen_hash',
                'sincronizacion_activa',
                'sincronizado_en',
                'sincronizacion_error',
            ]);
        });
    }
};
