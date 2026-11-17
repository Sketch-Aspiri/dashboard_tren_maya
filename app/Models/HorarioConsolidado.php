<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Libro semanal (lunes a domingo) para Coordinación, ya generado y guardado.
 */
class HorarioConsolidado extends Model
{
    protected $table = 'horario_consolidados';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'semana_inicio',
        'ruta',
        'nombre',
        'turnos',
        'cambios',
        'generado_por',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['semana_inicio' => 'date'];
    }

    public function generadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generado_por');
    }
}
