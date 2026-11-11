<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Actividad de Tickets creada por un evento del Dashboard (ver ActividadesEcosistema): folio y enlace para
 * mostrar el seguimiento junto al registro de origen.
 */
class EcosistemaVinculo extends Model
{
    protected $table = 'ecosistema_vinculos';

    protected $fillable = ['evento', 'ref', 'folio', 'url'];

    /**
     * @return MorphTo<Model, $this>
     */
    public function vinculable(): MorphTo
    {
        return $this->morphTo();
    }
}
