<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Deliberately generic/dummy fields — this is the disposable reference
     * CRUD module described in CLAUDE.md, adapted once the real data model
     * for the Jefe de Zona dashboard is provided.
     */
    public function up(): void
    {
        Schema::create('examples', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('value')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('examples');
    }
};
