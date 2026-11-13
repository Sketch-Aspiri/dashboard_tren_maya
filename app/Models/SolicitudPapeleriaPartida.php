<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Renglón de una solicitud de papelería: artículo, unidad, cantidad y
 * comentarios (las columnas del formato oficial).
 */
class SolicitudPapeleriaPartida extends Model
{
    protected $table = 'solicitud_papeleria_partidas';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'solicitud_papeleria_id', 'numero', 'catalogo_papeleria_id', 'articulo', 'unidad', 'cantidad', 'comentarios',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['numero' => 'integer', 'cantidad' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<SolicitudPapeleria, $this>
     */
    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(SolicitudPapeleria::class, 'solicitud_papeleria_id');
    }
}
