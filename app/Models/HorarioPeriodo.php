<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Un mes del horario oficial que sube Edificio. Resubir el mismo mes crea una
 * versión nueva y deja la anterior como "reemplazado".
 */
class HorarioPeriodo extends Model
{
    use LogsActivity;

    public const VIGENTE = 'vigente';

    public const REEMPLAZADO = 'reemplazado';

    public const BORRADOR = 'borrador';

    public const ORIGEN_EXCEL = 'excel';

    public const ORIGEN_SISTEMA = 'sistema';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'anio',
        'mes',
        'version',
        'estado',
        'origen',
        'archivo_ruta',
        'archivo_nombre',
        'archivo_hash',
        'subido_por',
        'publicado_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['publicado_at' => 'datetime'];
    }

    public function turnos(): HasMany
    {
        return $this->hasMany(HorarioTurno::class, 'periodo_id');
    }

    public function solicitudes(): HasMany
    {
        return $this->hasMany(HorarioSolicitud::class, 'periodo_id');
    }

    public function subidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subido_por');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->fillable)
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('horario');
    }
}
