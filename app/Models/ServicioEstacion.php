<?php

namespace App\Models;

use App\Enums\TipoServicio;
use Database\Factories\ServicioEstacionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Servicio de agua o energía eléctrica de una estación ("Estadísticas" ->
 * Gasto energético; ver app/Console/Commands/ImportGastoEnergeticoCommand.php).
 */
class ServicioEstacion extends Model
{
    /** @use HasFactory<ServicioEstacionFactory> */
    use HasFactory, LogsActivity;

    /**
     * Explicit table name: the English pluralizer would not produce
     * "servicios_estacion" from the class name.
     */
    protected $table = 'servicios_estacion';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'estacion_id',
        'tipo',
        'proveedor',
        'contrato',
        'observaciones',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo' => TipoServicio::class,
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->fillable)
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('servicio_estacion');
    }

    /**
     * @return BelongsTo<Estacion, $this>
     */
    public function estacion(): BelongsTo
    {
        return $this->belongsTo(Estacion::class);
    }

    /**
     * @return HasMany<PagoServicio, $this>
     */
    public function pagos(): HasMany
    {
        return $this->hasMany(PagoServicio::class);
    }
}
