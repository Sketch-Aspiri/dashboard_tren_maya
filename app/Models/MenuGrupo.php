<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Grupo del menú principal (p. ej. "RR.HH."). Ver MenuSeeder y
 * App\Support\MenuNavegacion.
 */
class MenuGrupo extends Model
{
    use LogsActivity;

    protected $table = 'menu_grupos';

    protected static function booted(): void
    {
        $olvidar = fn () => Cache::forget(Equipo::CACHE);
        static::saved($olvidar);
        static::deleted($olvidar);
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'nombre',
        'slug',
        'orden',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'orden' => 'integer',
        ];
    }

    /**
     * @return HasMany<Seccion, $this>
     */
    public function secciones(): HasMany
    {
        return $this->hasMany(Seccion::class)->orderBy('orden')->orderBy('nombre');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['nombre', 'slug', 'orden'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('menu');
    }
}
