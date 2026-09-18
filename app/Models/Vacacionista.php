<?php

namespace App\Models;

use Database\Factories\VacacionistaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * "Agenda Zona Oriente" -> Rol de vacaciones: una persona y su rol de
 * vacaciones de un año (ver app/Console/Commands/ImportRolVacacionesCommand.php).
 * Los periodos viven en VacacionPeriodo; el total de días se calcula, no se
 * persiste.
 */
class Vacacionista extends Model
{
    /** @use HasFactory<VacacionistaFactory> */
    use HasFactory, LogsActivity;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'anio',
        'no_empleado',
        'nombre_completo',
        'denominacion_puesto',
        'dias_otorgados',
        'estacion_id',
        'empleado_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'anio' => 'integer',
            'dias_otorgados' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->fillable)
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('vacacionista');
    }

    /**
     * @return BelongsTo<Estacion, $this>
     */
    public function estacion(): BelongsTo
    {
        return $this->belongsTo(Estacion::class);
    }

    /**
     * @return BelongsTo<Empleado, $this>
     */
    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class);
    }

    /**
     * @return HasMany<VacacionPeriodo, $this>
     */
    public function periodos(): HasMany
    {
        return $this->hasMany(VacacionPeriodo::class)->orderBy('fecha_inicio');
    }

    /**
     * Suma de días solicitados. Requiere `periodos` cargados (eager load)
     * para no disparar una consulta por fila.
     */
    public function getTotalDiasAttribute(): int
    {
        return (int) $this->periodos->sum('dias_solicitados');
    }
}
