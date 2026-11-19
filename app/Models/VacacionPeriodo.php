<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Un año del rol de vacaciones que sube la Zona. Resubir el mismo año crea una
 * versión nueva y deja la anterior como "reemplazado".
 */
class VacacionPeriodo extends Model
{
    use LogsActivity;

    public const VIGENTE = 'vigente';

    public const REEMPLAZADO = 'reemplazado';

    /**
     * @var list<string>
     */
    protected $fillable = ['anio', 'version', 'estado', 'archivo_ruta', 'archivo_nombre', 'archivo_hash', 'subido_por', 'publicado_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['publicado_at' => 'datetime'];
    }

    public function personas(): HasMany
    {
        return $this->hasMany(VacacionPersona::class, 'periodo_id');
    }

    public function tramos(): HasMany
    {
        return $this->hasMany(VacacionTramo::class, 'periodo_id');
    }

    public function solicitudes(): HasMany
    {
        return $this->hasMany(VacacionSolicitud::class, 'periodo_id');
    }

    public function subidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subido_por');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly($this->fillable)->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('vacaciones');
    }
}
