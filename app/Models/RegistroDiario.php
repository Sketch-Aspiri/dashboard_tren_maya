<?php

namespace App\Models;

use App\Enums\EstatusAsistencia;
use Database\Factories\RegistroDiarioFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Daily attendance record for one Empleado on one fecha. Part of Fase 1 —
 * "Control de Asistencia Diaria" (see the approved plan). No PII of the
 * identity/health kind Empleado carries, so unlike Empleado no field
 * redaction is needed in the activity log.
 */
class RegistroDiario extends Model
{
    /** @use HasFactory<RegistroDiarioFactory> */
    use HasFactory, LogsActivity;

    /**
     * Eloquent's English pluralizer would otherwise guess
     * "registro_diarios" (pluralizing only the last word) — explicit table
     * name avoids that, matching the migration's "registros_diarios".
     */
    protected $table = 'registros_diarios';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'empleado_id',
        'estacion_id',
        'fecha',
        'estatus',
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
            'estatus' => EstatusAsistencia::class,
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
            ->useLogName('registro_diario');
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
