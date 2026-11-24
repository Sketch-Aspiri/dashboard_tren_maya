<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Abordajes y boletos vendidos de una estación en un día. */
class AfluenciaDiaria extends Model
{
    use LogsActivity;

    protected $table = 'afluencia_diaria';

    /** @var list<string> */
    protected $fillable = ['estacion_id', 'fecha', 'abordan', 'boletos', 'capturado_por'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['fecha' => 'date', 'abordan' => 'integer', 'boletos' => 'integer'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['estacion_id', 'fecha', 'abordan', 'boletos'])->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('afluencia');
    }

    /** @return BelongsTo<Estacion, $this> */
    public function estacion(): BelongsTo
    {
        return $this->belongsTo(Estacion::class);
    }
}
