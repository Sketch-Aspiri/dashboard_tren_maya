<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Catálogo homologado de insumos del fondo rotatorio. Lo mantiene la Zona;
 * las estaciones solo lo consultan como autocompletado.
 */
class CatalogoInsumo extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'catalogo_insumos';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'descripcion',
        'unidad',
        'codigo',
        'ultimo_precio',
        'partida_presupuestal',
        'proveedor',
        'activo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ultimo_precio' => 'decimal:2',
            'activo' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->fillable)
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('catalogo_insumo');
    }
}
