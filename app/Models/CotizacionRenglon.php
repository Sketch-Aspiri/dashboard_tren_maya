<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Renglón de una Cotizacion. estatus: actualizado (su precio ya está en la
 * partida), revisar (no cumplió alguna regla; ver motivo) o sin_vincular (no
 * corresponde a ninguna partida: "cotizado fuera del documento"). Se audita a
 * través de la cotización.
 */
class CotizacionRenglon extends Model
{
    use HasFactory;

    public const ACTUALIZADO = 'actualizado';

    public const REVISAR = 'revisar';

    public const SIN_VINCULAR = 'sin_vincular';

    public const COINCIDENCIAS = ['mismo', 'otra_marca', 'distinto'];

    protected $table = 'cotizacion_renglones';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'cotizacion_id',
        'solicitud_insumo_partida_id',
        'numero',
        'clave',
        'descripcion',
        'cantidad',
        'precio_unitario',
        'importe',
        'coincidencia',
        'estatus',
        'motivo',
        'motivo_detalle',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'numero' => 'integer',
            'cantidad' => 'decimal:2',
            'precio_unitario' => 'decimal:2',
            'importe' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Cotizacion, $this>
     */
    public function cotizacion(): BelongsTo
    {
        return $this->belongsTo(Cotizacion::class);
    }

    /**
     * @return BelongsTo<SolicitudInsumoPartida, $this>
     */
    public function partida(): BelongsTo
    {
        return $this->belongsTo(SolicitudInsumoPartida::class, 'solicitud_insumo_partida_id');
    }
}
