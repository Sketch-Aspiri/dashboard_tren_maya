<?php

namespace App\Models;

use App\Services\Secciones\FotoService;
use App\Services\Secciones\VisibilidadSecciones;
use App\Support\Texto;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Fila de una tabla de una sección. Los valores viven en "datos" indexados
 * por SeccionColumna::clave.
 */
class SeccionFila extends Model
{
    use LogsActivity, SoftDeletes;

    protected $table = 'seccion_filas';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'datos',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'texto_busqueda',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'datos' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (SeccionFila $fila) => SeccionTabla::completarOrigen($fila));

        static::saving(function (SeccionFila $fila): void {
            $fila->texto_busqueda = self::textoBusqueda($fila->datos ?? []);
        });
    }

    /**
     * Copia plegada (minúsculas, sin acentos) de todos los valores, para el
     * buscador de la tabla. También la usa la importación masiva, que
     * inserta sin pasar por eventos de Eloquent.
     *
     * @param  array<string, mixed>  $datos
     */
    public static function textoBusqueda(array $datos): string
    {
        return implode(' | ', array_filter(
            array_map(fn ($valor) => FotoService::nombreDe($valor) === null ? Texto::normalizar($valor) : '', $datos),
            fn (string $valor) => $valor !== '',
        ));
    }

    /**
     * @return BelongsTo<Seccion, $this>
     */
    public function seccion(): BelongsTo
    {
        return $this->belongsTo(Seccion::class);
    }

    /**
     * @return BelongsTo<SeccionTabla, $this>
     */
    public function tabla(): BelongsTo
    {
        return $this->belongsTo(SeccionTabla::class, 'seccion_tabla_id')->withTrashed();
    }

    /**
     * @return HasMany<SeccionArchivo, $this>
     */
    public function archivos(): HasMany
    {
        return $this->hasMany(SeccionArchivo::class);
    }

    /**
     * @return BelongsTo<Estacion, $this>
     */
    public function estacion(): BelongsTo
    {
        return $this->belongsTo(Estacion::class);
    }

    /**
     * Solo las filas que el usuario puede ver: la zona ve todas; una cuenta
     * Estación, todas las filas de las secciones u hojas que tiene
     * permitidas (la estación etiquetada en la fila no cuenta para permisos).
     *
     * @param  Builder<SeccionFila>  $consulta
     */
    public function scopeVisiblePara(Builder $consulta, User $usuario): void
    {
        $alcance = app(VisibilidadSecciones::class)->alcancePara($usuario);

        if ($alcance === null) {
            return;
        }

        $consulta->where(fn (Builder $q) => $q
            ->whereIn($this->qualifyColumn('seccion_id'), $alcance['secciones'])
            ->orWhereIn($this->qualifyColumn('seccion_tabla_id'), $alcance['tablas']));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['seccion_id', 'seccion_tabla_id', 'estacion_id', 'datos'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('seccion');
    }
}
