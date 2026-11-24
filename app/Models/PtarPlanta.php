<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Planta de tratamiento de aguas residuales de una estación (una por estación). */
class PtarPlanta extends Model
{
    use LogsActivity;

    protected $table = 'ptar_plantas';

    /** @var list<string> */
    protected $fillable = ['estacion_id', 'numero', 'tramo', 'normas', 'tipo', 'empresa_operadora', 'activa'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['numero' => 'integer', 'normas' => 'array', 'activa' => 'boolean'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly($this->fillable)->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('ptar');
    }

    /**
     * Crea las plantas del catálogo (config/ptar.php) que falten; no toca las existentes. Devuelve cuántas creó.
     * Las estaciones que no existan en la base se omiten.
     */
    public static function sincronizar(): int
    {
        $creadas = 0;
        $existentes = self::query()->pluck('estacion_id')->all();
        $estaciones = Estacion::query()->whereIn('nombre', array_keys(config('ptar.plantas')))->get()->keyBy('nombre');

        foreach (config('ptar.plantas') as $nombre => $datos) {
            $estacion = $estaciones->get($nombre);
            if ($estacion === null || in_array($estacion->id, $existentes, true)) {
                continue;
            }
            self::query()->create([
                'estacion_id' => $estacion->id,
                'numero' => $datos['numero'],
                'tramo' => $datos['tramo'],
                'normas' => $datos['normas'],
                'empresa_operadora' => config('ptar.empresa_operadora'),
            ]);
            $creadas++;
        }

        return $creadas;
    }

    /** @return BelongsTo<Estacion, $this> */
    public function estacion(): BelongsTo
    {
        return $this->belongsTo(Estacion::class);
    }

    /** @return HasMany<PtarHallazgo, $this> */
    public function hallazgos(): HasMany
    {
        return $this->hasMany(PtarHallazgo::class, 'planta_id');
    }
}
