<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Links a station-account user (role "Estación") to its Estacion.
     * Deliberately not mass-assignable — see App\Models\User and
     * App\Console\Commands\CreateEstacionAccountCommand.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('estacion_id')->nullable()->after('email')
                ->constrained('estaciones')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('estacion_id');
        });
    }
};
