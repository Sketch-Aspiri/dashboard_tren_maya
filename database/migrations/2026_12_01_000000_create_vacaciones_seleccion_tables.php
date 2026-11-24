<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('empleado_id')->nullable()->unique()->after('estacion_id')->constrained('empleados')->nullOnDelete();
        });

        Schema::create('vacacion_catalogo_periodos', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('anio');
            $table->date('inicio');
            $table->date('fin');
            $table->unsignedTinyInteger('dias');
            $table->unsignedTinyInteger('trimestre');
            $table->boolean('autorizado')->default(true);
            $table->string('nota', 120)->nullable();
            $table->timestamps();

            $table->unique(['anio', 'inicio', 'dias']);
        });

        Schema::create('vacacion_dias_inhabiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('anio');
            $table->date('fecha');
            $table->string('nombre', 80);
            $table->timestamps();

            $table->unique(['anio', 'fecha']);
        });

        Schema::create('vacacion_estacion_topes', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('anio');
            $table->foreignId('estacion_id')->constrained('estaciones')->cascadeOnDelete();
            $table->unsignedTinyInteger('max_simultaneos')->default(2);
            $table->timestamps();

            $table->unique(['anio', 'estacion_id']);
        });

        Schema::create('vacacion_planes', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('anio');
            $table->foreignId('empleado_id')->constrained('empleados')->cascadeOnDelete();
            $table->foreignId('estacion_id')->constrained('estaciones')->cascadeOnDelete();
            $table->unsignedTinyInteger('dias_correspondientes');
            $table->string('estado', 12)->default('borrador');
            $table->timestamp('enviada_at')->nullable();
            $table->foreignId('resuelta_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resuelta_at')->nullable();
            $table->string('comentario', 500)->nullable();
            $table->timestamps();

            $table->unique(['anio', 'empleado_id']);
            $table->index(['anio', 'estacion_id', 'estado']);
        });

        Schema::create('vacacion_selecciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('vacacion_planes')->cascadeOnDelete();
            $table->unsignedTinyInteger('trimestre');
            $table->foreignId('catalogo_periodo_id')->constrained('vacacion_catalogo_periodos')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['plan_id', 'trimestre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacacion_selecciones');
        Schema::dropIfExists('vacacion_planes');
        Schema::dropIfExists('vacacion_estacion_topes');
        Schema::dropIfExists('vacacion_dias_inhabiles');
        Schema::dropIfExists('vacacion_catalogo_periodos');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('empleado_id');
        });
    }
};
