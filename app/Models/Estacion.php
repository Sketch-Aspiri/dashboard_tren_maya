<?php

namespace App\Models;

use Database\Factories\EstacionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fixed catalog of Zona Oriente stations (9 operational + "Edificio Zonal
 * Este", the zone office). Seeded via database/seeders/EstacionSeeder.php
 * — not user-editable in this phase, so no LogsActivity trait.
 */
class Estacion extends Model
{
    /** @use HasFactory<EstacionFactory> */
    use HasFactory;

    /**
     * Eloquent's English pluralizer would otherwise guess "estacions" —
     * explicit table name avoids that.
     */
    protected $table = 'estaciones';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'nombre',
        'is_operativa',
        'orden',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_operativa' => 'boolean',
            'orden' => 'integer',
        ];
    }

    /**
     * @return HasMany<Empleado, $this>
     */
    public function empleados(): HasMany
    {
        return $this->hasMany(Empleado::class);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<RegistroDiario, $this>
     */
    public function registrosDiarios(): HasMany
    {
        return $this->hasMany(RegistroDiario::class);
    }

    /**
     * @return HasMany<ComisionadoVisitante, $this>
     */
    public function comisionadosVisitantes(): HasMany
    {
        return $this->hasMany(ComisionadoVisitante::class);
    }

    /**
     * @return HasMany<ComisionadoFuera, $this>
     */
    public function comisionadosFuera(): HasMany
    {
        return $this->hasMany(ComisionadoFuera::class);
    }
}
