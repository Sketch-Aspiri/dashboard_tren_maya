<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Periodos de vacaciones de cada vacacionista, agrupados por trimestre
     * (1-4). Un trimestre puede tener más de un periodo (el Excel los
     * captura separados por salto de línea en la misma celda).
     */
    public function up(): void
    {
        Schema::create('vacaciones_periodos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vacacionista_id')->constrained('vacacionistas')->cascadeOnDelete();
            $table->unsignedTinyInteger('trimestre');
            $table->date('fecha_inicio');
            $table->date('fecha_termino');
            $table->unsignedSmallInteger('dias_solicitados');
            $table->timestamps();

            $table->index(['fecha_inicio', 'fecha_termino']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vacaciones_periodos');
    }
};
