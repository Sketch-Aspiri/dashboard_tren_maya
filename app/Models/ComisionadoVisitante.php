<?php

namespace App\Models;

use Database\Factories\ComisionadoVisitanteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Personnel from OTHER coordinations/departments physically present at one
 * of Zona Oriente's stations today (Etapa 2 — Control de Asistencia
 * Diaria, see the approved plan). Not part of the `empleados` roster —
 * captured as free-text entries.
 */
class ComisionadoVisitante extends Model
{
    /** @use HasFactory<ComisionadoVisitanteFactory> */
    use HasFactory, LogsActivity;

    /**
     * Eloquent's English pluralizer would otherwise guess
     * "comisionado_visitantes" (pluralizing only the last word) — explicit
     * table name avoids that, matching the migration's
     * "comisionados_visitantes".
     */
    protected $table = 'comisionados_visitantes';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'estacion_id',
        'fecha',
        'no_trabajador',
        'nombre',
        'direccion_origen',
        'motivo',
        'fecha_inicio',
        'fecha_fin',
        'notas',
        'registrado_por',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->fillable)
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('comisionado_visitante');
    }

    /**
     * @return BelongsTo<Estacion, $this>
     */
    public function estacion(): BelongsTo
    {
        return $this->belongsTo(Estacion::class);
    }
}
