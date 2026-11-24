<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Oficio de comisión subido por una estación (pdf, word o imagen). El archivo
 * vive en el disco privado y solo se alcanza por OficioComisionController.
 * Se borra suave: el instante del borrado marca desactualizado el control ya
 * generado.
 */
class OficioComision extends Model
{
    use SoftDeletes;

    protected $table = 'oficios_comision';

    /** @var list<string> */
    protected $fillable = ['estacion_id', 'nombre_original', 'ruta', 'extension', 'tamano', 'vigente_hasta', 'subido_por'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['vigente_hasta' => 'date', 'tamano' => 'integer'];
    }

    public function estacion(): BelongsTo
    {
        return $this->belongsTo(Estacion::class);
    }

    public function vigenteEn(\DateTimeInterface $fecha): bool
    {
        return $this->vigente_hasta === null || $this->vigente_hasta->toDateString() >= $fecha->format('Y-m-d');
    }
}
