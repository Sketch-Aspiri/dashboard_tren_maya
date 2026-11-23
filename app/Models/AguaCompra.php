<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Una compra de garrafones (un "pago" del Excel) con su comprobante. */
class AguaCompra extends Model
{
    use LogsActivity;

    public const CAPTURA = 'captura';

    public const IMPORTACION = 'importacion';

    protected $table = 'agua_compras';

    /** @var list<string> */
    protected $fillable = [
        'registro_id', 'fecha', 'garrafones', 'costo_unitario', 'monto', 'comprobante_ruta', 'comprobante_nombre',
        'nota', 'origen', 'capturo_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['fecha' => 'date', 'garrafones' => 'integer', 'costo_unitario' => 'decimal:2', 'monto' => 'decimal:2'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['registro_id', 'fecha', 'garrafones', 'monto', 'nota', 'origen'])->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('agua');
    }

    /** @return BelongsTo<AguaRegistro, $this> */
    public function registro(): BelongsTo
    {
        return $this->belongsTo(AguaRegistro::class, 'registro_id');
    }
}
