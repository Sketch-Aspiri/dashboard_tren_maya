<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Solicitud mensual de papelería de una estación. Estados: borrador ->
 * enviada -> (observada | aprobada). La estación solo edita en borrador u
 * observada.
 */
class SolicitudPapeleria extends Model
{
    use HasFactory, LogsActivity;

    public const BORRADOR = 'borrador';

    public const ENVIADA = 'enviada';

    public const OBSERVADA = 'observada';

    public const APROBADA = 'aprobada';

    private const ETIQUETAS = [
        self::BORRADOR => 'Borrador',
        self::ENVIADA => 'Enviada',
        self::OBSERVADA => 'Observada',
        self::APROBADA => 'Aprobada',
    ];

    protected $table = 'solicitudes_papeleria';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'estacion_id', 'anio', 'mes', 'estado', 'enviada_at', 'revisada_por', 'revisada_at', 'comentario_zona',
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
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->fillable)
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('solicitud_papeleria');
    }

    public function etiquetaEstado(): string
    {
        return self::ETIQUETAS[$this->estado] ?? ucfirst($this->estado);
    }

    public function estaAprobada(): bool
    {
        return $this->estado === self::APROBADA;
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
     * @return HasMany<SolicitudPapeleriaPartida, $this>
     */
    public function partidas(): HasMany
    {
        return $this->hasMany(SolicitudPapeleriaPartida::class)->orderBy('numero');
    }
}
