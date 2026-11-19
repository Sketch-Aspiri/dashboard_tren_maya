<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Cambio que pide la estación a un tramo de vacaciones. Todo lo que se envía junto comparte `lote`;
 * la Zona autoriza o rechaza el lote completo y, al autorizar, se modifica el rol.
 */
class VacacionSolicitud extends Model
{
    use LogsActivity;

    public const PENDIENTE = 'pendiente';

    public const AUTORIZADA = 'autorizada';

    public const RECHAZADA = 'rechazada';

    public const CANCELADA = 'cancelada';

    protected $table = 'vacacion_solicitudes';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'periodo_id', 'lote', 'estacion_id', 'tramo_id', 'solicitante_id', 'no_empleado', 'nombre', 'trimestre',
        'inicio_origen', 'fin_origen', 'dias_origen', 'nuevo_inicio', 'nuevo_fin', 'nuevos_dias', 'motivo',
        'estado', 'resuelta_por', 'resuelta_at', 'comentario_resolucion', 'aplicada',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['inicio_origen' => 'date', 'fin_origen' => 'date', 'nuevo_inicio' => 'date', 'nuevo_fin' => 'date', 'resuelta_at' => 'datetime', 'aplicada' => 'boolean'];
    }

    public function periodo(): BelongsTo
    {
        return $this->belongsTo(VacacionPeriodo::class, 'periodo_id');
    }

    public function estacion(): BelongsTo
    {
        return $this->belongsTo(Estacion::class);
    }

    public function tramo(): BelongsTo
    {
        return $this->belongsTo(VacacionTramo::class, 'tramo_id');
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitante_id');
    }

    public function resolvio(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resuelta_por');
    }

    public function esPendiente(): bool
    {
        return $this->estado === self::PENDIENTE;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['estado', 'comentario_resolucion', 'aplicada'])->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('vacaciones');
    }
}
