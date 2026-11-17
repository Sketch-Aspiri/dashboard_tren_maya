<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('horario_periodos', function (Blueprint $table) {
            $table->string('origen', 20)->default('excel')->after('estado');
        });

        Schema::table('horario_turnos', function (Blueprint $table) {
            $table->foreignId('editado_por')->nullable()->after('comida_oficial')->constrained('users')->nullOnDelete();
            $table->timestamp('editado_at')->nullable()->after('editado_por');
            $table->string('nota', 500)->nullable()->after('editado_at');
        });

        Schema::table('horario_solicitudes', function (Blueprint $table) {
            $table->uuid('lote')->nullable()->after('periodo_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('horario_solicitudes', function (Blueprint $table) {
            $table->dropColumn('lote');
        });

        Schema::table('horario_turnos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('editado_por');
            $table->dropColumn(['editado_at', 'nota']);
        });

        Schema::table('horario_periodos', function (Blueprint $table) {
            $table->dropColumn('origen');
        });
    }
};
