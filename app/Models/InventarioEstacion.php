<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Lo que una estación tiene según sus resguardos: herramienta y equipo de
 * protección (existencia) y consumibles entregados (solo la última entrega).
 */
class InventarioEstacion extends Model
{
    use HasFactory, LogsActivity;

    public const HERRAMIENTA = 'HERRAMIENTA';

    public const EPP = 'EPP';

    public const CONSUMIBLE = 'CONSUMIBLE';

    protected $table = 'inventario_estaciones';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'estacion_id', 'tipo', 'clave', 'descripcion', 'unidad', 'cantidad',
        'primer_mes', 'ultimo_mes', 'partida_presupuestal', 'fuente',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['cantidad' => 'decimal:2'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['estacion_id', 'tipo', 'descripcion', 'cantidad', 'partida_presupuestal'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('inventario_estacion');
    }

    /**
     * @return BelongsTo<Estacion, $this>
     */
    public function estacion(): BelongsTo
    {
        return $this->belongsTo(Estacion::class);
    }
}
