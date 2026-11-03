<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cómo se lee una hoja de un Excel/CSV de la nube hacia una tabla espejo:
 * fila de encabezados (índice base 0), encabezado normalizado -> clave de
 * columna y columna llave. Se confirma una vez; después la sincronización lo
 * aplica sola. Todo lo asigna el servidor (forceFill); la bitácora la
 * escribe MapeoEspejoService con eventos explícitos.
 *
 * `columnas`: list<array{encabezado_normalizado: string, clave: string}>;
 * la clave 'ignorar' marca una columna con datos que el usuario decidió no
 * importar. `retenido`: cambio masivo o hoja vacía que espera Aplicar/Ignorar.
 * `seccion_tabla_id` no tiene FK (ver la migración): la integridad es de la app.
 */
class SeccionMapeo extends Model
{
    public const IGNORAR = 'ignorar';

    protected $table = 'seccion_mapeos';

    /**
     * Nada viene del cliente: lo asigna el servidor con forceFill.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fila_encabezado' => 'integer',
            'columnas' => 'array',
            'retenido' => 'array',
            'confirmado_en' => 'datetime',
        ];
    }

    public function estaRetenido(): bool
    {
        return $this->retenido !== null;
    }

    /**
     * @return BelongsTo<SeccionOrigen, $this>
     */
    public function origen(): BelongsTo
    {
        return $this->belongsTo(SeccionOrigen::class, 'seccion_origen_id');
    }

    /**
     * @return BelongsTo<SeccionArchivo, $this>
     */
    public function archivo(): BelongsTo
    {
        return $this->belongsTo(SeccionArchivo::class, 'seccion_archivo_id')->withTrashed();
    }

    /**
     * @return BelongsTo<SeccionTabla, $this>
     */
    public function tabla(): BelongsTo
    {
        return $this->belongsTo(SeccionTabla::class, 'seccion_tabla_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function confirmador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmado_por');
    }
}
