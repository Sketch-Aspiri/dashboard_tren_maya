<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Concepto (renglón) de una Factura, con el vínculo a la partida del mes y al
 * renglón de la cotización con que se comparó. `vinculo`: clave, texto,
 * documento o manual (puesto por una persona; el análisis no lo cambia).
 */
class FacturaConcepto extends Model
{
    use HasFactory;

    protected $table = 'factura_conceptos';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'factura_id', 'numero', 'clave_prod_serv', 'no_identificacion', 'unidad', 'descripcion',
        'cantidad', 'valor_unitario', 'descuento', 'importe', 'iva_tasa', 'iva_importe',
        'solicitud_insumo_partida_id', 'cotizacion_renglon_id', 'vinculo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'numero' => 'integer',
            'cantidad' => 'decimal:4',
            'valor_unitario' => 'decimal:4',
            'descuento' => 'decimal:2',
            'importe' => 'decimal:2',
            'iva_tasa' => 'decimal:2',
            'iva_importe' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Factura, $this>
     */
    public function factura(): BelongsTo
    {
        return $this->belongsTo(Factura::class);
    }

    /**
     * @return BelongsTo<SolicitudInsumoPartida, $this>
     */
    public function partida(): BelongsTo
    {
        return $this->belongsTo(SolicitudInsumoPartida::class, 'solicitud_insumo_partida_id');
    }

    /**
     * @return BelongsTo<CotizacionRenglon, $this>
     */
    public function renglonCotizacion(): BelongsTo
    {
        return $this->belongsTo(CotizacionRenglon::class, 'cotizacion_renglon_id');
    }
}
