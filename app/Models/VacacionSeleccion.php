<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VacacionSeleccion extends Model
{
    protected $table = 'vacacion_selecciones';

    /** @var list<string> */
    protected $fillable = ['plan_id', 'trimestre', 'catalogo_periodo_id'];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(VacacionPlan::class, 'plan_id');
    }

    public function periodo(): BelongsTo
    {
        return $this->belongsTo(VacacionCatalogoPeriodo::class, 'catalogo_periodo_id');
    }
}
