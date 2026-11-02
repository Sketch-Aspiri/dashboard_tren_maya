<?php

namespace App\Models;

use App\Enums\EstadoOrigen;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Lo que una sección tiene vinculado a la nube. Hoy: un archivo por enlace
 * público. Todo lo asigna el servidor (forceFill), nada viene del cliente.
 * Las altas, cambios y errores se registran con eventos explícitos de la
 * bitácora (NubeSyncService), por eso no usa LogsActivity.
 */
class SeccionOrigen extends Model
{
    use SoftDeletes;

    public const TIPO_ARCHIVO = 'archivo';

    protected $table = 'seccion_origenes';

    /**
     * El enlace compartido da acceso al archivo a quien lo tenga: no se serializa.
     *
     * @var list<string>
     */
    protected $hidden = ['enlace_original'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estado' => EstadoOrigen::class,
            'alimenta_tablas' => 'boolean',
            'sincronizado_en' => 'datetime',
            'resumen' => 'array',
        ];
    }

    /**
     * @param  Builder<SeccionOrigen>  $consulta
     * @return Builder<SeccionOrigen>
     */
    public function scopeActivos(Builder $consulta): Builder
    {
        return $consulta->where('estado', EstadoOrigen::Activo->value);
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
        return $this->hasMany(SeccionArchivo::class, 'seccion_origen_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }
}
