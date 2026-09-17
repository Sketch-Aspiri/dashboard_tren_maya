<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Fixed catalog of Zona Oriente stations (9 operational + "Edificio
     * Zonal Este", the zone office). Not user-editable in this phase —
     * seeded via EstacionSeeder, no LogsActivity needed.
     */
    public function up(): void
    {
        Schema::create('estaciones', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique();
            $table->boolean('is_operativa')->default(true);
            $table->unsignedInteger('orden')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('estaciones');
    }
};
