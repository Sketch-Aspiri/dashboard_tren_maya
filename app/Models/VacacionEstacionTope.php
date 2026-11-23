<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Cuántas personas de una estación pueden estar de vacaciones al mismo tiempo en el año. */
class VacacionEstacionTope extends Model
{
    public const PREDETERMINADO = 2;

    protected $table = 'vacacion_estacion_topes';

    /** @var list<string> */
    protected $fillable = ['anio', 'estacion_id', 'max_simultaneos'];
}
