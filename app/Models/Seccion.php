<?php

namespace App\Models;

use Database\Factories\SeccionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Sección dinámica del menú: una o varias tablas configurables por el
 * usuario (cada una con sus columnas y filas) más un repositorio de
 * documentos. Sustituye a los módulos fijos por concepto del PPT
 * "Dashboard Tren Maya".
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
     * @return HasMany<SeccionTabla, $this>
     */
    public function tablas(): HasMany
    {
        return $this->hasMany(SeccionTabla::class)->orderBy('orden')->orderBy('id');
    }

    /**
     * La tabla pedida (si es de esta sección) o la primera; si la sección
     * aún no tiene ninguna, crea la tabla por defecto.
     */
    public function tablaActiva(?int $id = null): SeccionTabla
    {
        if ($id !== null && ($tabla = $this->tablas()->whereKey($id)->first()) !== null) {
            return $tabla;
        }

        if (($primera = $this->tablas()->first()) !== null) {
            return $primera;
        }

        // Dos peticiones simultáneas a una sección sin tablas no deben crear dos "Principal".
        return DB::transaction(function (): SeccionTabla {
            self::query()->whereKey($this->getKey())->lockForUpdate()->first();

            return $this->tablas()->first() ?? $this->tablas()->create([
                'nombre' => SeccionTabla::NOMBRE_POR_DEFECTO,
                'orden' => 1,
            ]);
        });
    }

    /**
     * Columnas de TODAS las tablas de la sección. Solo para agregados
     * (conteos, catálogo); para pintar o editar una tabla usar
     * SeccionTabla::columnas().
     *
     * @return HasMany<SeccionColumna, $this>
     */
    public function columnas(): HasMany
    {
        return $this->hasMany(SeccionColumna::class)->orderBy('orden')->orderBy('id');
    }

    /**
     * Filas de TODAS las tablas de la sección (seccion_id denormalizado).
     *
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
