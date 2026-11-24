<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Registro mensual de la PTAR de una estación: modo de operación, equipos, actividades de mantenimiento,
 * observaciones y fotos. Estados: borrador -> enviada -> (observada | aprobada).
 */
class PtarRegistro extends Model
{
    use LogsActivity;

    public const BORRADOR = 'borrador';

    public const ENVIADA = 'enviada';

    public const OBSERVADA = 'observada';

    public const APROBADA = 'aprobada';

    public const MODO_AUTOMATICO = 'automatico';

    public const MODO_MANUAL = 'manual';

    public const MODO_FUERA_SERVICIO = 'fuera_servicio';

    public const MODO_SIN_DATO = 'sin_dato';

    private const ETIQUETAS = [
        self::BORRADOR => 'Borrador',
        self::ENVIADA => 'Enviada',
        self::OBSERVADA => 'Observada',
        self::APROBADA => 'Aprobada',
    ];

    private const ETIQUETAS_MODO = [
        self::MODO_AUTOMATICO => 'Automático',
        self::MODO_MANUAL => 'Manual',
        self::MODO_FUERA_SERVICIO => 'Fuera de servicio',
        self::MODO_SIN_DATO => 'Sin dato',
    ];

    protected $table = 'ptar_registros';

    /** @var list<string> */
    protected $fillable = [
        'estacion_id', 'anio', 'mes', 'estado', 'modo', 'modo_detalle', 'operador', 'observaciones_equipos', 'observaciones_generales',
        'enviada_at', 'revisada_por', 'revisada_at', 'comentario_zona',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['anio' => 'integer', 'mes' => 'integer', 'enviada_at' => 'datetime', 'revisada_at' => 'datetime'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly($this->fillable)->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('ptar');
    }

    public function etiquetaEstado(): string
    {
        return self::ETIQUETAS[$this->estado] ?? ucfirst($this->estado);
    }

    public function etiquetaModo(): string
    {
        return self::ETIQUETAS_MODO[$this->modo] ?? ucfirst($this->modo);
    }

    public function estaAprobada(): bool
    {
        return $this->estado === self::APROBADA;
    }

    public function esEditablePorEstacion(): bool
    {
        return in_array($this->estado, [self::BORRADOR, self::OBSERVADA], true);
    }

    /** @return BelongsTo<Estacion, $this> */
    public function estacion(): BelongsTo
    {
        return $this->belongsTo(Estacion::class);
    }

    /** @return HasOne<PtarPlanta, $this> */
    public function planta(): HasOne
    {
        return $this->hasOne(PtarPlanta::class, 'estacion_id', 'estacion_id');
    }

    /** @return BelongsTo<User, $this> */
    public function revisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revisada_por');
    }

    /** @return HasMany<PtarEquipo, $this> */
    public function equipos(): HasMany
    {
        return $this->hasMany(PtarEquipo::class, 'registro_id')->orderBy('id');
    }

    /** @return HasMany<PtarActividad, $this> */
    public function actividades(): HasMany
    {
        return $this->hasMany(PtarActividad::class, 'registro_id')->orderBy('id');
    }

    /** @return HasMany<PtarEvidencia, $this> */
    public function evidencias(): HasMany
    {
        return $this->hasMany(PtarEvidencia::class, 'registro_id')->orderBy('id');
    }
}
