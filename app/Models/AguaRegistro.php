<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Registro mensual de agua (garrafones) de una estación: el programa del mes (lo fija la Zona) y las compras
 * que captura la estación con su comprobante. Estados: borrador -> enviada -> (observada | aprobada).
 * El agua es un fondo aparte: no cuenta contra el límite de insumos.
 */
class AguaRegistro extends Model
{
    use LogsActivity;

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

    protected $table = 'agua_registros';

    /** @var list<string> */
    protected $fillable = [
        'estacion_id', 'anio', 'mes', 'estado', 'frecuencia', 'consumo_mensual', 'costo_unitario',
        'garrafones_propios', 'garrafones_comodato', 'referencia', 'dia_entrega',
        'enviada_at', 'revisada_por', 'revisada_at', 'comentario_zona',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'anio' => 'integer', 'mes' => 'integer', 'consumo_mensual' => 'integer', 'costo_unitario' => 'decimal:2',
            'garrafones_propios' => 'integer', 'garrafones_comodato' => 'integer',
            'enviada_at' => 'datetime', 'revisada_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly($this->fillable)->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('agua');
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

    /** Cuota asignada del mes = consumo mensual neto × costo unitario (como en el Excel). */
    public function cuota(): float
    {
        return round($this->consumo_mensual * (float) $this->costo_unitario, 2);
    }

    public function totalComprado(): float
    {
        return round((float) $this->compras->sum('monto'), 2);
    }

    public function garrafonesComprados(): int
    {
        return (int) $this->compras->sum('garrafones');
    }

    /** Total comprado − cuota: negativo = pendiente de entrega; positivo = reintegro (columna del Excel). */
    public function saldo(): float
    {
        return round($this->totalComprado() - $this->cuota(), 2);
    }

    /** @return BelongsTo<Estacion, $this> */
    public function estacion(): BelongsTo
    {
        return $this->belongsTo(Estacion::class);
    }

    /** @return BelongsTo<User, $this> */
    public function revisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revisada_por');
    }

    /** @return HasMany<AguaCompra, $this> */
    public function compras(): HasMany
    {
        return $this->hasMany(AguaCompra::class, 'registro_id')->orderBy('fecha')->orderBy('id');
    }
}
