<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VacacionDiaInhabil extends Model
{
    protected $table = 'vacacion_dias_inhabiles';

    /** @var list<string> */
    protected $fillable = ['anio', 'fecha', 'nombre'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['fecha' => 'date'];
    }
}
