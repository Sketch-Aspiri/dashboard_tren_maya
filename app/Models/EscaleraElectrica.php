<?php

namespace App\Models;

use Database\Factories\EscaleraElectricaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Una escalera eléctrica de una estación (módulo "Controles"; ver
 * app/Console/Commands/ImportControlesCommand.php). Varias pueden
 * pertenecer a la misma estación.
 */
class EscaleraElectrica extends Model
{
    /** @use HasFactory<EscaleraElectricaFactory> */
    use HasFactory, LogsActivity;

    /**
     * Eloquent's English pluralizer would otherwise guess
     * "escalera_electricas" (pluralizing only the last word) — explicit
     * table name avoids that, matching the migration's
     * "escaleras_electricas".
     */
    protected $table = 'escaleras_electricas';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'estacion_id',
        'identificador',
        'modelo',
        'anio_instalacion',
        'tipo',
        'operativo',
        'estado_barandales',
        'estado_boton_paro_emergencia',
        'fecha_ultimo_mantenimiento',
        'observaciones',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'anio_instalacion' => 'integer',
            'fecha_ultimo_mantenimiento' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->fillable)
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('escalera_electrica');
    }

    /**
     * @return BelongsTo<Estacion, $this>
     */
    public function estacion(): BelongsTo
    {
        return $this->belongsTo(Estacion::class);
    }
}
