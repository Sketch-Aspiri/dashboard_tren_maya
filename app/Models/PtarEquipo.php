<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Estatus de un equipo de la PTAR en un mes (bomba, sopladores, difusores, panel…). */
class PtarEquipo extends Model
{
    use LogsActivity;

    public const OPERANDO = 'operando';

    public const PARCIAL = 'parcial';

    public const FUERA_SERVICIO = 'fuera_servicio';

    public const SIN_DATO = 'sin_dato';

    /** @var array<string, string> */
    public const ETIQUETAS = [
        self::OPERANDO => 'Operando',
        self::PARCIAL => 'Operando parcial',
        self::FUERA_SERVICIO => 'Fuera de servicio',
        self::SIN_DATO => 'Sin dato',
    ];

    protected $table = 'ptar_equipos';

    /** @var list<string> */
    protected $fillable = ['registro_id', 'equipo', 'estatus', 'observaciones'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly($this->fillable)->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('ptar');
    }

    public function etiquetaEquipo(): string
    {
        return config('ptar.equipos.'.$this->equipo, $this->equipo);
    }

    public function etiquetaEstatus(): string
    {
        return self::ETIQUETAS[$this->estatus] ?? $this->estatus;
    }

    /** @return BelongsTo<PtarRegistro, $this> */
    public function registro(): BelongsTo
    {
        return $this->belongsTo(PtarRegistro::class, 'registro_id');
    }
}
