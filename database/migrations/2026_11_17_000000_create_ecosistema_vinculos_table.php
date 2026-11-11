<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seguimiento en Tickets de lo que ocurre en el Dashboard: cada actividad que Tickets crea por un evento
 * (solicitud observada, factura cancelada en el SAT, asistencia sin capturar) se liga a su registro de origen
 * para mostrar «Seguimiento en Tickets: ACT-2026-0012». `ref` identifica el hecho y es único: reenviar el
 * mismo evento no duplica el vínculo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ecosistema_vinculos', function (Blueprint $table) {
            $table->id();
            $table->morphs('vinculable');
            $table->string('evento', 40);
            $table->string('ref', 120)->unique();
            $table->string('folio', 30)->nullable();
            $table->string('url', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecosistema_vinculos');
    }
};
