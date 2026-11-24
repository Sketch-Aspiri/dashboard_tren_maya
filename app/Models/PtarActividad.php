<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Actividad de mantenimiento de la PTAR (cárcamo, tolva, canaletas…) con su última fecha. */
class PtarActividad extends Model
{
    use LogsActivity;

    protected $table = 'ptar_actividades';

    /** @var list<string> */
    protected $fillable = ['registro_id', 'actividad', 'ultima_fecha', 'nota'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['ultima_fecha' => 'date'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly($this->fillable)->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('ptar');
    }

    /** Vencido: la última fecha supera los días de alerta (sin fecha no se considera vencido, solo "sin dato"). */
    public function estaVencida(): bool
    {
        return $this->ultima_fecha !== null
            && $this->ultima_fecha->diffInDays(now(), true) > (int) config('ptar.dias_alerta_mantenimiento');
    }

    /** @return BelongsTo<PtarRegistro, $this> */
    public function registro(): BelongsTo
    {
        return $this->belongsTo(PtarRegistro::class, 'registro_id');
    }
}
