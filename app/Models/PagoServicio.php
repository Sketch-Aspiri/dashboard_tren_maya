<?php

namespace App\Models;

use Database\Factories\PagoServicioFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Monto pagado por un ServicioEstacion en un mes ("Estadísticas" -> Gasto
 * energético). Capturado/corregido desde la pantalla de la estación o
 * cargado por app:import-gasto-energetico; toda alta, cambio o baja queda en
 * la bitácora de auditoría.
 */
class PagoServicio extends Model
{
    /** @use HasFactory<PagoServicioFactory> */
    use HasFactory, LogsActivity;

    protected $table = 'pagos_servicio';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'servicio_estacion_id',
        'anio',
        'mes',
        'monto',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'anio' => 'integer',
            'mes' => 'integer',
            'monto' => 'decimal:2',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->fillable)
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('pago_servicio');
    }

    /**
     * @return BelongsTo<ServicioEstacion, $this>
     */
    public function servicio(): BelongsTo
    {
        return $this->belongsTo(ServicioEstacion::class, 'servicio_estacion_id');
    }
}
