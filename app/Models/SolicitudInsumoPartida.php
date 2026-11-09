<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Partida de una SolicitudInsumo. La estación captura cantidad, unidad,
 * descripción y justificación; código, precio, partida presupuestal y
 * proveedor los completa la Zona al revisar. Se audita a través de la
 * solicitud (que registra envío, devolución y aprobación).
 */
class SolicitudInsumoPartida extends Model
{
    use HasFactory;

    protected $table = 'solicitud_insumo_partidas';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'solicitud_insumo_id',
        'numero',
        'cantidad',
        'unidad',
        'descripcion',
        'justificacion',
        'catalogo_insumo_id',
        'codigo',
        'precio_unitario',
        'partida_presupuestal',
        'proveedor',
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
        ];
    }

    public function total(): ?float
    {
        return $this->precio_unitario === null
            ? null
            : round((float) $this->cantidad * (float) $this->precio_unitario, 2);
    }

    /**
     * @return BelongsTo<SolicitudInsumo, $this>
     */
    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(SolicitudInsumo::class, 'solicitud_insumo_id');
    }

    /**
     * @return BelongsTo<CatalogoInsumo, $this>
     */
    public function catalogo(): BelongsTo
    {
        return $this->belongsTo(CatalogoInsumo::class, 'catalogo_insumo_id');
    }
}
