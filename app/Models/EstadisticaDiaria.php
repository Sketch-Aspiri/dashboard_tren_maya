<?php

namespace App\Models;

use Database\Factories\EstadisticaDiariaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Daily passenger flow / tickets sold for one Estacion on one fecha.
 * Módulo "Estadísticas" (ver el plan aprobado). One row per estación+fecha,
 * only persisted once that day has real captured data — never a zero row
 * for a future/un-captured day. Monthly/annual summaries are computed via
 * SUM()/GROUP BY in EstadisticaDiariaService, never persisted separately.
 */
class EstadisticaDiaria extends Model
{
    /** @use HasFactory<EstadisticaDiariaFactory> */
    use HasFactory, LogsActivity;

    /**
     * Eloquent's English pluralizer would otherwise guess
     * "estadistica_diarias" (pluralizing only the last word) — explicit
     * table name avoids that, matching the migration's
     * "estadisticas_diarias".
     */
    protected $table = 'estadisticas_diarias';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'estacion_id',
        'fecha',
        'abordan',
        'boletos_vendidos',
        'registrado_por',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'abordan' => 'integer',
            'boletos_vendidos' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->fillable)
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('estadistica_diaria');
    }

    /**
     * @return BelongsTo<Estacion, $this>
     */
    public function estacion(): BelongsTo
    {
        return $this->belongsTo(Estacion::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
