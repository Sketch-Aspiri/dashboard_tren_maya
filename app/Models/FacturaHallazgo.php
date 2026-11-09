<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hallazgo de la comprobación de una Factura (una fila por hallazgo): tipo
 * OK, ALERTA, ERROR o REVISAR, con el valor cotizado, el facturado y su
 * diferencia cuando aplica. Se recalcula completo cada vez que se analiza la
 * factura.
 */
class FacturaHallazgo extends Model
{
    use HasFactory;

    protected $table = 'factura_hallazgos';

    /**
     * @var list<string>
     */
    protected $fillable = ['factura_id', 'factura_concepto_id', 'tipo', 'codigo', 'detalle', 'valor_cotizado', 'valor_facturado', 'diferencia'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valor_cotizado' => 'decimal:4',
            'valor_facturado' => 'decimal:4',
            'diferencia' => 'decimal:4',
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
     * @return BelongsTo<FacturaConcepto, $this>
     */
    public function concepto(): BelongsTo
    {
        return $this->belongsTo(FacturaConcepto::class, 'factura_concepto_id');
    }
}
