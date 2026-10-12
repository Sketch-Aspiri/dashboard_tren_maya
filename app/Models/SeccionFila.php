<?php

namespace App\Models;

use App\Support\Texto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Fila de la tabla de una sección. Los valores viven en "datos" indexados
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
            array_map(fn ($valor) => Texto::normalizar($valor), $datos),
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
     * @return HasMany<SeccionArchivo, $this>
     */
    public function archivos(): HasMany
    {
        return $this->hasMany(SeccionArchivo::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['seccion_id', 'datos'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('seccion');
    }
}
