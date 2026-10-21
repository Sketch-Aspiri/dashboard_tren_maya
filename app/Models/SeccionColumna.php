<?php

namespace App\Models;

use App\Enums\TipoColumna;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Columna de una tabla de una sección. "clave" es inmutable una vez creada:
 * es la llave del valor dentro de seccion_filas.datos.
 */
class SeccionColumna extends Model
{
    use LogsActivity;

    protected $table = 'seccion_columnas';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'seccion_id',
        'seccion_tabla_id',
        'nombre',
        'clave',
        'tipo',
        'opciones',
        'orden',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo' => TipoColumna::class,
            'opciones' => 'array',
            'orden' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (SeccionColumna $columna) => SeccionTabla::completarOrigen($columna));
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
     * @return list<string>
     */
    public function listaOpciones(): array
    {
        return array_values(array_filter(
            array_map(fn ($opcion) => trim((string) $opcion), $this->opciones ?? []),
            fn (string $opcion) => $opcion !== '',
        ));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['seccion_id', 'seccion_tabla_id', 'nombre', 'clave', 'tipo', 'opciones', 'orden'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('seccion');
    }
}
