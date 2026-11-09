<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Cotización de un proveedor para los insumos de un mes. Sus renglones se
 * vinculan con partidas de las solicitudes; el PDF original se guarda en el
 * disco privado.
 */
class Cotizacion extends Model
{
    use HasFactory, LogsActivity;

    public const OK = 'OK';

    public const REVISAR = 'REVISAR';

    protected $table = 'cotizaciones';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'anio',
        'mes',
        'proveedor',
        'folio',
        'fecha',
        'subtotal',
        'iva',
        'total',
        'archivo_nombre',
        'archivo_ruta',
        'validacion',
        'validacion_detalle',
        'leida_con_ia',
        'creada_por',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'anio' => 'integer',
            'mes' => 'integer',
            'fecha' => 'date',
            'subtotal' => 'decimal:2',
            'iva' => 'decimal:2',
            'total' => 'decimal:2',
            'leida_con_ia' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['anio', 'mes', 'proveedor', 'folio', 'fecha', 'subtotal', 'iva', 'total', 'validacion', 'leida_con_ia'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('cotizacion');
    }

    /**
     * @return HasMany<CotizacionRenglon, $this>
     */
    public function renglones(): HasMany
    {
        return $this->hasMany(CotizacionRenglon::class)->orderBy('numero');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creada_por');
    }
}
