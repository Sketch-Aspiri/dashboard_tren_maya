<?php

namespace App\Models;

use App\Support\Areas;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Equipo (área) de una estación: p. ej. RH o RM. La clave es la misma en Portal y Tickets y no cambia.
 * Define qué grupos del menú (y sus módulos) usa y en qué estaciones existe.
 */
class Equipo extends Model
{
    use LogsActivity;

    public const CACHE = 'equipos.mapa';

    /** @var list<string> */
    protected $fillable = ['clave', 'nombre'];

    protected static function booted(): void
    {
        $olvidar = fn () => Cache::forget(self::CACHE);
        static::saved($olvidar);
        static::deleted($olvidar);
    }

    /** ¿Existe este equipo en la estación? El jefe de estación (no es un equipo) existe en todas. */
    public static function existeEn(string $clave, int $estacionId): bool
    {
        if ($clave === Areas::JEFE) {
            return true;
        }

        return static::query()->where('clave', $clave)->whereHas('estaciones', fn ($q) => $q->whereKey($estacionId))->exists();
    }

    /** @return BelongsToMany<Estacion, $this> */
    public function estaciones(): BelongsToMany
    {
        return $this->belongsToMany(Estacion::class, 'equipo_estacion');
    }

    /** @return BelongsToMany<MenuGrupo, $this> */
    public function menuGrupos(): BelongsToMany
    {
        return $this->belongsToMany(MenuGrupo::class, 'equipo_menu_grupo');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['clave', 'nombre'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
