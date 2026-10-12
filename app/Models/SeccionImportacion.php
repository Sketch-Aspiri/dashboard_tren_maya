<?php

namespace App\Models;

use App\Enums\EstadoImportacion;
use App\Enums\ModoImportacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Carga de un documento con tabla(s) sobre una sección. No usa
 * LogsActivity: ImportacionService registra en la bitácora un evento
 * resumen al aplicarla/deshacerla (las filas masivas no se registran una
 * por una).
 *
 * "tablas": list<array{nombre: string, filas: list<list<string|null>>}>
 * — tal como se detectaron en el documento, sin encabezados elegidos.
 * "cambios": registro para deshacer — ['actualizadas' => [id => datos
 * anteriores], 'eliminadas' => [ids], 'columnas_creadas' => [ids]].
 */
class SeccionImportacion extends Model
{
    protected $table = 'seccion_importaciones';

    public const ORIGEN_ARCHIVO = 'archivo';

    public const ORIGEN_IA = 'ia';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'seccion_archivo_id',
        'origen',
        'estado',
        'modo',
        'tablas',
        'resumen',
        'cambios',
        'aplicada_at',
        'deshecha_at',
    ];

    /**
     * El registro de cambios puede ser grande; nunca se serializa.
     *
     * @var list<string>
     */
    protected $hidden = [
        'tablas',
        'cambios',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estado' => EstadoImportacion::class,
            'modo' => ModoImportacion::class,
            'tablas' => 'array',
            'resumen' => 'array',
            'cambios' => 'array',
            'aplicada_at' => 'datetime',
            'deshecha_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Seccion, $this>
     */
    public function seccion(): BelongsTo
    {
        return $this->belongsTo(Seccion::class);
    }

    /**
     * @return BelongsTo<SeccionArchivo, $this>
     */
    public function archivo(): BelongsTo
    {
        return $this->belongsTo(SeccionArchivo::class, 'seccion_archivo_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function isVistaPrevia(): bool
    {
        return $this->estado === EstadoImportacion::VistaPrevia;
    }
}
