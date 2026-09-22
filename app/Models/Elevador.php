<?php

namespace App\Models;

use Database\Factories\ElevadorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Un elevador de una estación (módulo "Controles"; ver
 * app/Console/Commands/ImportControlesCommand.php). Varios pueden
 * pertenecer a la misma estación.
 */
class Elevador extends Model
{
    /** @use HasFactory<ElevadorFactory> */
    use HasFactory, LogsActivity;

    /**
     * Eloquent's English pluralizer would otherwise guess "elevadors" —
     * explicit table name avoids that, matching the migration's
     * "elevadores".
     */
    protected $table = 'elevadores';

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
        'fecha_ultimo_mantenimiento',
        'estado_puertas_cabina_botoneras',
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
            ->useLogName('elevador');
    }

    /**
     * @return BelongsTo<Estacion, $this>
     */
    public function estacion(): BelongsTo
    {
        return $this->belongsTo(Estacion::class);
    }
}
