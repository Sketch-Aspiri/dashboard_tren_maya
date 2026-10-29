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
        'parent_id',
        'nombre',
        'slug',
        'descripcion',
        'orden',
        'is_visible',
        'solo_documentos',
    ];

    /** Niveles máximos del árbol (la raíz es el nivel 1). */
    public const PROFUNDIDAD_MAXIMA = 6;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'orden' => 'integer',
            'is_visible' => 'boolean',
            'solo_documentos' => 'boolean',
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
     * @return BelongsTo<Seccion, $this>
     */
    public function padre(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Seccion, $this>
     */
    public function hijas(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('orden')->orderBy('nombre');
    }

    /**
     * Ancestros de la raíz a la más cercana (para migas de pan).
     *
     * @return list<Seccion>
     */
    public function ancestros(): array
    {
        $ruta = [];
        $actual = $this->padre;

        while ($actual !== null && count($ruta) < self::PROFUNDIDAD_MAXIMA) {
            array_unshift($ruta, $actual);
            $actual = $actual->padre;
        }

        return $ruta;
    }

    /** Nivel en el árbol: 1 para una sección raíz. */
    public function nivel(): int
    {
        return count($this->ancestros()) + 1;
    }

    /**
     * Ids de todas las descendientes (hijas, nietas…), recorrido por niveles.
     *
     * @return list<int>
     */
    public function descendientesIds(): array
    {
        $ids = [];
        $nivel = [$this->getKey()];

        while ($nivel !== [] && count($ids) < 10000) {
            $nivel = self::query()->whereIn('parent_id', $nivel)->pluck('id')->all();
            $ids = array_merge($ids, $nivel);
        }

        return $ids;
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
     * @return HasMany<SeccionEnlace, $this>
     */
    public function enlaces(): HasMany
    {
        return $this->hasMany(SeccionEnlace::class);
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
            ->logOnly(['menu_grupo_id', 'parent_id', 'nombre', 'slug', 'descripcion', 'orden', 'is_visible', 'solo_documentos'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('seccion');
    }
}
