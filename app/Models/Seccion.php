<?php

namespace App\Models;

use Database\Factories\SeccionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Sección dinámica del menú: una tabla configurable por el usuario
 * (columnas + filas) más un repositorio de documentos. Sustituye a los
 * módulos fijos por concepto del PPT "Dashboard Tren Maya".
 */
class Seccion extends Model
{
    /** @use HasFactory<SeccionFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected $table = 'secciones';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'menu_grupo_id',
        'nombre',
        'slug',
        'descripcion',
        'orden',
        'is_visible',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'orden' => 'integer',
            'is_visible' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return BelongsTo<MenuGrupo, $this>
     */
    public function grupo(): BelongsTo
    {
        return $this->belongsTo(MenuGrupo::class, 'menu_grupo_id');
    }

    /**
     * @return HasMany<SeccionColumna, $this>
     */
    public function columnas(): HasMany
    {
        return $this->hasMany(SeccionColumna::class)->orderBy('orden')->orderBy('id');
    }

    /**
     * @return HasMany<SeccionFila, $this>
     */
    public function filas(): HasMany
    {
        return $this->hasMany(SeccionFila::class);
    }

    /**
     * @return HasMany<SeccionArchivo, $this>
     */
    public function archivos(): HasMany
    {
        return $this->hasMany(SeccionArchivo::class);
    }

    /**
     * @return HasMany<SeccionImportacion, $this>
     */
    public function importaciones(): HasMany
    {
        return $this->hasMany(SeccionImportacion::class);
    }

    /**
     * Alias para el scoping implícito de rutas: Laravel deduce el nombre de
     * la relación pluralizando el parámetro {importacion} en inglés
     * ("importacions"). Así /secciones/{seccion}/importaciones/{importacion}
     * solo resuelve importaciones de ESA sección.
     *
     * @return HasMany<SeccionImportacion, $this>
     */
    public function importacions(): HasMany
    {
        return $this->importaciones();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['menu_grupo_id', 'nombre', 'slug', 'descripcion', 'orden', 'is_visible'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('seccion');
    }
}
