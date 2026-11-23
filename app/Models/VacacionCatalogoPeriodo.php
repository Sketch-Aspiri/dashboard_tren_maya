<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Periodo de vacaciones que el personal puede elegir (lunes a viernes, 5 o 10 días hábiles netos de inhábiles). */
class VacacionCatalogoPeriodo extends Model
{
    protected $table = 'vacacion_catalogo_periodos';

    /** @var list<string> */
    protected $fillable = ['anio', 'inicio', 'fin', 'dias', 'trimestre', 'autorizado', 'nota'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['inicio' => 'date', 'fin' => 'date', 'autorizado' => 'boolean'];
    }

    public function selecciones(): HasMany
    {
        return $this->hasMany(VacacionSeleccion::class, 'catalogo_periodo_id');
    }

    /** Texto como en el Excel: "5 al 9 Ene. 2027" o "26 Ene. al 9 Feb. 2027". */
    public function etiqueta(): string
    {
        $mes = fn ($f) => ucfirst(rtrim($f->copy()->locale('es')->translatedFormat('M'), '.')).'.';

        return $this->inicio->month === $this->fin->month
            ? $this->inicio->day.' al '.$this->fin->day.' '.$mes($this->fin).' '.$this->fin->year
            : $this->inicio->day.' '.$mes($this->inicio).' al '.$this->fin->day.' '.$mes($this->fin).' '.$this->fin->year;
    }
}
