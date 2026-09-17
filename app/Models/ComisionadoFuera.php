<?php

namespace App\Models;

use Database\Factories\ComisionadoFueraFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Zona Oriente's OWN employees commissioned OUT to somewhere else today
 * (Etapa 2 — Control de Asistencia Diaria, see the approved plan).
 *
 * Deliberately independent from RegistroDiario: an empleado can have an
 * active daily status (e.g. Vacaciones) AND a ComisionadoFuera entry for
 * the same fecha at the same time — confirmed against the real reference
 * "oficio" document. There is no unique(empleado_id, fecha) constraint.
 */
class ComisionadoFuera extends Model
{
    /** @use HasFactory<ComisionadoFueraFactory> */
    use HasFactory, LogsActivity;

    /**
     * Eloquent's English pluralizer would otherwise guess
     * "comisionado_fueras" — explicit table name avoids that, matching the
     * migration's "comisionados_fuera".
     */
    protected $table = 'comisionados_fuera';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'empleado_id',
        'estacion_id',
        'fecha',
        'coordinacion_destino',
        'ubicacion_destino',
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
            ->useLogName('comisionado_fuera');
    }

    /**
     * @return BelongsTo<Empleado, $this>
     */
    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class);
    }

    /**
     * @return BelongsTo<Estacion, $this>
     */
    public function estacion(): BelongsTo
    {
        return $this->belongsTo(Estacion::class);
    }
}
