<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Selección de vacaciones de una persona para un año: borrador → enviada → autorizada o rechazada. */
class VacacionPlan extends Model
{
    public const BORRADOR = 'borrador';

    public const ENVIADA = 'enviada';

    public const AUTORIZADA = 'autorizada';

    public const RECHAZADA = 'rechazada';

    protected $table = 'vacacion_planes';

    /** @var list<string> */
    protected $fillable = ['anio', 'empleado_id', 'estacion_id', 'dias_correspondientes', 'estado', 'enviada_at', 'resuelta_por', 'resuelta_at', 'comentario'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['enviada_at' => 'datetime', 'resuelta_at' => 'datetime'];
    }

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class);
    }

    public function estacion(): BelongsTo
    {
        return $this->belongsTo(Estacion::class);
    }

    public function selecciones(): HasMany
    {
        return $this->hasMany(VacacionSeleccion::class, 'plan_id');
    }

    /** Se puede elegir mientras no esté enviada ni autorizada. */
    public function editable(): bool
    {
        return in_array($this->estado, [self::BORRADOR, self::RECHAZADA], true);
    }

    public function diasElegidos(): int
    {
        return (int) $this->selecciones->sum(fn (VacacionSeleccion $s) => $s->periodo->dias);
    }
}
