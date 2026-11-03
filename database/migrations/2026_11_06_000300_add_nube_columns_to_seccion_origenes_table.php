<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Solo aditiva. `tipo`, `nombre` y `enlace_original` ya existen. La
     * cuenta usa nullOnDelete: las cuentas se dan de baja con soft delete y
     * un borrado físico no debe impedirse por los orígenes que la usaron.
     */
    public function up(): void
    {
        Schema::table('seccion_origenes', function (Blueprint $table) {
            $table->foreignId('nube_cuenta_id')->nullable()->after('seccion_id')
                ->constrained('nube_cuentas')->nullOnDelete();
            $table->string('remoto_drive_id', 255)->nullable()->after('proveedor');
            $table->string('remoto_item_id', 255)->nullable()->after('remoto_drive_id');
            $table->boolean('incluir_subcarpetas')->default(true)->after('nombre');
        });
    }

    public function down(): void
    {
        Schema::table('seccion_origenes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('nube_cuenta_id');
            $table->dropColumn(['remoto_drive_id', 'remoto_item_id', 'incluir_subcarpetas']);
        });
    }
};
