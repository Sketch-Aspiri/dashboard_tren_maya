<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permiso de una estación sobre UNA hoja (tabla) de una sección, además del
 * que tenga sobre la sección completa. Los permisos solo suman: el nivel
 * efectivo en la hoja es el mayor de los dos (ver AccesoEstaciones).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seccion_tabla_estacion', function (Blueprint $table) {
            $table->id();
            // Sin FK a propósito (como seccion_mapeos): una FK hacia seccion_tablas
            // impediría revertir la migración antigua create_seccion_tablas
            // (MigracionTablasTest la revierte). Las hojas se dan de baja con
            // soft delete y VisibilidadSecciones ignora las dadas de baja.
            $table->unsignedBigInteger('seccion_tabla_id');
            $table->foreignId('estacion_id')->constrained('estaciones')->cascadeOnDelete();
            $table->string('nivel', 10)->default('ver');
            $table->foreignId('aprobado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['seccion_tabla_id', 'estacion_id']);
            $table->index('estacion_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seccion_tabla_estacion');
    }
};
