<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Solo aditiva. `contenido_hash`: huella del contenido de la hoja ya
     * aplicado (o ignorado) para no reprocesar lo que no cambió. `retenido`:
     * cambio masivo o hoja vacía que espera Aplicar / Ignorar.
     */
    public function up(): void
    {
        Schema::table('seccion_mapeos', function (Blueprint $table) {
            $table->char('contenido_hash', 64)->nullable()->after('llave_clave');
            $table->json('retenido')->nullable()->after('contenido_hash');
        });
    }

    public function down(): void
    {
        Schema::table('seccion_mapeos', function (Blueprint $table) {
            $table->dropColumn(['contenido_hash', 'retenido']);
        });
    }
};
