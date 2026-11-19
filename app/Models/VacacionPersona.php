<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una persona dentro del rol de vacaciones de un año: días que le corresponden y sus cuatro trimestres.
 */
class VacacionPersona extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['periodo_id', 'estacion_id', 'no_empleado', 'empleado_id', 'nombre', 'puesto', 'dias_otorgados', 'orden'];

    public function periodo(): BelongsTo
    {
        return $this->belongsTo(VacacionPeriodo::class, 'periodo_id');
    }

    public function estacion(): BelongsTo
    {
        return $this->belongsTo(Estacion::class);
    }

    public function tramos(): HasMany
    {
        return $this->hasMany(VacacionTramo::class, 'persona_id')->orderBy('trimestre');
    }

    public function diasSolicitados(): int
    {
        return (int) $this->tramos->sum('dias');
    }
}
