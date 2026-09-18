<?php

namespace App\Models;

use Database\Factories\VacacionPeriodoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un periodo de vacaciones (inicio-término) de un Vacacionista, dentro de un
 * trimestre del año. Se reemplaza en bloque al reimportar el rol, por eso no
 * lleva LogsActivity propio (la auditoría vive en Vacacionista).
 */
class VacacionPeriodo extends Model
{
    /** @use HasFactory<VacacionPeriodoFactory> */
    use HasFactory;

    protected $table = 'vacaciones_periodos';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'vacacionista_id',
        'trimestre',
        'fecha_inicio',
        'fecha_termino',
        'dias_solicitados',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trimestre' => 'integer',
            'fecha_inicio' => 'date',
            'fecha_termino' => 'date',
            'dias_solicitados' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Vacacionista, $this>
     */
    public function vacacionista(): BelongsTo
    {
        return $this->belongsTo(Vacacionista::class);
    }
}
