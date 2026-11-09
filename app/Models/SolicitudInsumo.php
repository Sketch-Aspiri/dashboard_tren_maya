<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Solicitud mensual de insumos del fondo rotatorio de una estación.
 * Estados: borrador -> enviada -> (observada | aprobada). La estación solo
 * edita en borrador u observada.
 */
class SolicitudInsumo extends Model
{
    use HasFactory, LogsActivity;

    public const BORRADOR = 'borrador';

    public const ENVIADA = 'enviada';

    public const OBSERVADA = 'observada';

    public const APROBADA = 'aprobada';

    public const ENVIADA_COORDINACION = 'enviada_coordinacion';

    public const DEVUELTA = 'devuelta';

    public const ESTADOS = [self::BORRADOR, self::ENVIADA, self::OBSERVADA, self::APROBADA, self::ENVIADA_COORDINACION, self::DEVUELTA];

    /** Estados en los que la Zona ya aprobó la solicitud (cuentan en el consolidado). */
    public const ESTADOS_APROBADOS = [self::APROBADA, self::ENVIADA_COORDINACION, self::DEVUELTA];

    private const ETIQUETAS = [
        self::BORRADOR => 'Borrador',
        self::ENVIADA => 'Enviada',
        self::OBSERVADA => 'Observada',
        self::APROBADA => 'Aprobada',
        self::ENVIADA_COORDINACION => 'Enviada a Coordinación',
        self::DEVUELTA => 'Devuelta por Coordinación',
    ];

    protected $table = 'solicitudes_insumos';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'estacion_id',
        'anio',
        'mes',
        'tipo_servicio',
        'lugar_trabajo',
        'justificacion_general',
        'estado',
        'solicitante_nombre',
        'solicitante_puesto',
        'enviada_at',
        'revisada_por',
        'revisada_at',
        'comentario_zona',
        'oficio_numero',
        'enviada_coordinacion_at',
        'devuelta_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'anio' => 'integer',
            'mes' => 'integer',
            'enviada_at' => 'datetime',
            'revisada_at' => 'datetime',
            'enviada_coordinacion_at' => 'datetime',
            'devuelta_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->fillable)
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('solicitud_insumo');
    }

    public function etiquetaEstado(): string
    {
        return self::ETIQUETAS[$this->estado] ?? ucfirst($this->estado);
    }

    public function estaAprobada(): bool
    {
        return in_array($this->estado, self::ESTADOS_APROBADOS, true);
    }

    public function esEditablePorEstacion(): bool
    {
        return in_array($this->estado, [self::BORRADOR, self::OBSERVADA], true);
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
    public function revisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revisada_por');
    }

    /**
     * @return HasMany<SolicitudInsumoPartida, $this>
     */
    public function partidas(): HasMany
    {
        return $this->hasMany(SolicitudInsumoPartida::class)->orderBy('numero');
    }
}
