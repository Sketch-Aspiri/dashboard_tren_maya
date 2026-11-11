<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Factura CFDI del fondo rotatorio, cargada desde su XML. El XML y el PDF
 * se guardan en el disco privado. `estado` resume sus hallazgos: ERROR,
 * REVISAR, ALERTA u OK (el peor de todos).
 */
class Factura extends Model
{
    use HasFactory, LogsActivity;

    public const OK = 'OK';

    public const ALERTA = 'ALERTA';

    public const REVISAR = 'REVISAR';

    public const ERROR = 'ERROR';

    /** Del peor al mejor. */
    public const GRAVEDAD = [self::ERROR, self::REVISAR, self::ALERTA, self::OK];

    protected $table = 'facturas';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'anio', 'mes', 'uuid', 'serie', 'folio', 'fecha', 'fecha_timbrado',
        'emisor_rfc', 'emisor_nombre', 'receptor_rfc', 'receptor_nombre',
        'tipo_comprobante', 'moneda', 'metodo_pago',
        'subtotal', 'descuento', 'iva', 'isr_retenido', 'otros_retenidos', 'total',
        'referencia_cot', 'cotizacion_id', 'vinculo_cotizacion', 'estacion_id',
        'xml_ruta', 'xml_nombre', 'pdf_ruta', 'pdf_nombre',
        'estado', 'verificacion_sat', 'sat_es_cancelable', 'sat_estatus_cancelacion', 'sat_detalle', 'sat_consultado_at', 'creada_por',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'anio' => 'integer',
            'mes' => 'integer',
            'fecha' => 'datetime',
            'fecha_timbrado' => 'datetime',
            'sat_consultado_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'descuento' => 'decimal:2',
            'iva' => 'decimal:2',
            'isr_retenido' => 'decimal:2',
            'otros_retenidos' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['anio', 'mes', 'uuid', 'serie', 'folio', 'emisor_rfc', 'subtotal', 'iva', 'isr_retenido', 'total', 'cotizacion_id', 'estacion_id', 'estado'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('factura');
    }

    /**
     * Folio tal como se muestra: serie + folio.
     */
    public function referencia(): string
    {
        return trim(($this->serie ? $this->serie.' ' : '').$this->folio);
    }

    /**
     * @return HasMany<FacturaConcepto, $this>
     */
    public function conceptos(): HasMany
    {
        return $this->hasMany(FacturaConcepto::class)->orderBy('numero');
    }

    /**
     * @return HasMany<FacturaHallazgo, $this>
     */
    public function hallazgos(): HasMany
    {
        return $this->hasMany(FacturaHallazgo::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<Cotizacion, $this>
     */
    public function cotizacion(): BelongsTo
    {
        return $this->belongsTo(Cotizacion::class);
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
    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creada_por');
    }

    /**
     * Actividades de Tickets creadas por eventos de este registro (ver ActividadesEcosistema).
     *
     * @return MorphMany<EcosistemaVinculo, $this>
     */
    public function vinculosTickets(): MorphMany
    {
        return $this->morphMany(EcosistemaVinculo::class, 'vinculable')->orderBy('id');
    }
}
