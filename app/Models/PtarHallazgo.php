<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Problemática o propuesta de una PTAR con seguimiento (abierto -> en proceso -> cerrado). */
class PtarHallazgo extends Model
{
    use LogsActivity;

    public const PROBLEMATICA = 'problematica';

    public const PROPUESTA = 'propuesta';

    public const ABIERTO = 'abierto';

    public const EN_PROCESO = 'en_proceso';

    public const CERRADO = 'cerrado';

    protected $table = 'ptar_hallazgos';

    /** @var list<string> */
    protected $fillable = [
        'planta_id', 'registro_origen_id', 'tipo', 'equipo', 'descripcion', 'propuesta', 'prioridad', 'estado',
        'fecha_detectado', 'fecha_compromiso', 'fecha_cierre', 'tarjeta', 'tickets_enviado_at', 'capturo_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fecha_detectado' => 'date', 'fecha_compromiso' => 'date', 'fecha_cierre' => 'date',
            'tickets_enviado_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly($this->fillable)->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('ptar');
    }

    public function estaAbierto(): bool
    {
        return $this->estado !== self::CERRADO;
    }

    /** Abierto con fecha compromiso ya pasada. */
    public function estaVencido(): bool
    {
        return $this->estaAbierto() && $this->fecha_compromiso !== null && $this->fecha_compromiso->isPast();
    }

    /**
     * Actividades de Tickets creadas desde este hallazgo (folio y enlace; las guarda EnviarActividadTickets).
     *
     * @return MorphMany<EcosistemaVinculo, $this>
     */
    public function vinculosTickets(): MorphMany
    {
        return $this->morphMany(EcosistemaVinculo::class, 'vinculable')->orderBy('id');
    }

    /** @return BelongsTo<PtarPlanta, $this> */
    public function planta(): BelongsTo
    {
        return $this->belongsTo(PtarPlanta::class, 'planta_id');
    }

    /** @return BelongsTo<PtarRegistro, $this> */
    public function registroOrigen(): BelongsTo
    {
        return $this->belongsTo(PtarRegistro::class, 'registro_origen_id');
    }
}
