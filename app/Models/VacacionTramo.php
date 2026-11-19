<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Los días de vacaciones de una persona en un trimestre (inicio a fin, días hábiles). Lo oficial
 * se conserva al ajustarlo (cambio autorizado o edición de la Zona).
 */
class VacacionTramo extends Model
{
    public const OFICIAL = 'oficial';

    public const AJUSTADO = 'ajustado';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'periodo_id', 'persona_id', 'estacion_id', 'trimestre', 'inicio', 'fin', 'dias', 'origen',
        'inicio_oficial', 'fin_oficial', 'dias_oficial', 'editado_por', 'editado_at', 'nota',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['inicio' => 'date', 'fin' => 'date', 'inicio_oficial' => 'date', 'fin_oficial' => 'date', 'editado_at' => 'datetime'];
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(VacacionPersona::class, 'persona_id');
    }

    public function estacion(): BelongsTo
    {
        return $this->belongsTo(Estacion::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editado_por');
    }

    /** Guarda lo oficial antes del primer ajuste. */
    public function recordarOficial(): void
    {
        if ($this->origen === self::OFICIAL) {
            $this->fill(['inicio_oficial' => $this->inicio, 'fin_oficial' => $this->fin, 'dias_oficial' => $this->dias, 'origen' => self::AJUSTADO]);
        }
    }

    public function vacio(): bool
    {
        return $this->inicio === null || $this->fin === null;
    }
}
