<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una persona en un día: la "tabla" del horario. Los cambios autorizados
 * modifican jornada/comida y guardan lo oficial para poder compararlo.
 */
class HorarioTurno extends Model
{
    public const OFICIAL = 'oficial';

    public const AJUSTADO = 'ajustado';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'periodo_id',
        'estacion_id',
        'no_empleado',
        'empleado_id',
        'nombre',
        'puesto',
        'fecha',
        'jornada',
        'comida',
        'semana',
        'origen',
        'jornada_oficial',
        'comida_oficial',
        'editado_por',
        'editado_at',
        'nota',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['fecha' => 'date', 'editado_at' => 'datetime'];
    }

    public function periodo(): BelongsTo
    {
        return $this->belongsTo(HorarioPeriodo::class, 'periodo_id');
    }

    public function estacion(): BelongsTo
    {
        return $this->belongsTo(Estacion::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editado_por');
    }

    public function ajustado(): bool
    {
        return $this->origen === self::AJUSTADO;
    }

    /**
     * Guarda lo oficial la primera vez que el turno se modifica.
     */
    public function recordarOficial(): void
    {
        if (! $this->ajustado()) {
            $this->jornada_oficial = $this->jornada;
            $this->comida_oficial = $this->comida;
            $this->origen = self::AJUSTADO;
        }
    }
}
