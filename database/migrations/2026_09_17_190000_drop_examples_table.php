<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The disposable "Examples" reference CRUD module (see CLAUDE.md —
     * "disposable/adaptable" scaffolding) has been superseded by the real
     * "Agenda Zona Oriente" / Empleado module and is being removed. The
     * original create-table migration
     * (2026_09_15_190540_create_examples_table.php) is left untouched per
     * this project's convention of never mutating/deleting a migration
     * that has already run — this migration drops the table instead.
     */
    public function up(): void
    {
        Schema::dropIfExists('examples');
    }

    /**
     * Reverse the migrations.
     *
     * Recreates the `examples` table with the exact same columns as the
     * original migration, so a rollback restores the prior schema shape.
     */
    public function down(): void
    {
        Schema::create('examples', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('value')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }
};
