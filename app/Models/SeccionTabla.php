<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Una de las tablas de una sección (p. ej. una hoja de un libro de Excel).
 * Las columnas y filas cuelgan de ella; seccion_id queda denormalizado en
 * ambas para consultar por sección sin pasar por la tabla.
 */
class SeccionTabla extends Model
{
    use LogsActivity, SoftDeletes;

    public const NOMBRE_POR_DEFECTO = 'Principal';

    protected $table = 'seccion_tablas';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'seccion_id',
        'nombre',
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
     * Completa seccion_id y seccion_tabla_id de una columna/fila que se
     * crea con Eloquent trayendo solo uno de los dos: con la tabla toma la
     * sección; con solo la sección usa su tabla activa. Las inserciones en
     * bloque (importación) no pasan por aquí y traen ambos explícitos.
     */
    public static function completarOrigen(SeccionColumna|SeccionFila $modelo): void
    {
        if ($modelo->seccion_tabla_id !== null) {
            // La sección siempre sale de la tabla: seccion_id nunca contradice a seccion_tabla_id.
            $modelo->seccion_id = self::withTrashed()->whereKey($modelo->seccion_tabla_id)->value('seccion_id');

            return;
        }

        if ($modelo->seccion_id !== null) {
            $modelo->seccion_tabla_id = Seccion::query()->findOrFail($modelo->seccion_id)->tablaActiva()->id;
        }
    }

    /**
     * @return BelongsTo<Seccion, $this>
     */
    public function seccion(): BelongsTo
    {
        return $this->belongsTo(Seccion::class);
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

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['seccion_id', 'nombre', 'orden'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('seccion');
    }
}
