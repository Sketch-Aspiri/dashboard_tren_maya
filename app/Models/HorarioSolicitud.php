<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Petición de la estación para cambiar el turno de una persona (cambio_turno)
 * o mover su trabajo a otra fecha (cambio_fecha: se intercambian los dos días).
 * Edificio la autoriza o rechaza; al autorizar se modifica la tabla.
 */
class HorarioSolicitud extends Model
{
    use LogsActivity;

    public const CAMBIO_TURNO = 'cambio_turno';

    public const CAMBIO_FECHA = 'cambio_fecha';

    public const PENDIENTE = 'pendiente';

    public const AUTORIZADA = 'autorizada';

    public const RECHAZADA = 'rechazada';

    public const CANCELADA = 'cancelada';

    protected $table = 'horario_solicitudes';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'periodo_id',
        'lote',
        'estacion_id',
        'turno_id',
        'solicitante_id',
        'no_empleado',
        'nombre',
        'fecha',
        'tipo',
        'jornada_origen',
        'comida_origen',
        'nueva_jornada',
        'nueva_comida',
        'nueva_fecha',
        'motivo',
        'estado',
        'resuelta_por',
        'resuelta_at',
        'comentario_resolucion',
        'aplicada',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'nueva_fecha' => 'date',
            'resuelta_at' => 'datetime',
            'aplicada' => 'boolean',
        ];
    }

    public function periodo(): BelongsTo
    {
        return $this->belongsTo(HorarioPeriodo::class, 'periodo_id');
    }

    public function estacion(): BelongsTo
    {
        return $this->belongsTo(Estacion::class);
    }

    public function turno(): BelongsTo
    {
        return $this->belongsTo(HorarioTurno::class, 'turno_id');
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

    public function etiquetaEstado(): string
    {
        return match ($this->estado) {
            self::PENDIENTE => 'Pendiente',
            self::AUTORIZADA => 'Autorizada',
            self::RECHAZADA => 'Rechazada',
            default => 'Cancelada',
        };
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
