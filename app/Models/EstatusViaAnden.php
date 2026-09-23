<?php

namespace App\Models;

use Database\Factories\EstatusViaAndenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Estatus de una vía (y su andén) de una estación (módulo "Controles"; ver
 * app/Console/Commands/ImportEstatusViasAndenesCommand.php). Una estación
 * tiene varias vías.
 */
class EstatusViaAnden extends Model
{
    /** @use HasFactory<EstatusViaAndenFactory> */
    use HasFactory, LogsActivity;

    /**
     * Explicit table name: Eloquent's English pluralizer would not guess
     * "estatus_vias_andenes".
     */
    protected $table = 'estatus_vias_andenes';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'estacion_id',
        'via',
        'anden',
        'estatus_anden',
        'estatus_via',
        'senaletica',
        'teleindicadores',
        'pruebas_galibo',
        'riesgos_obstaculos',
        'comentarios',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'via' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->fillable)
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('estatus_via_anden');
    }

    /**
     * @return BelongsTo<Estacion, $this>
     */
    public function estacion(): BelongsTo
    {
        return $this->belongsTo(Estacion::class);
    }
}
