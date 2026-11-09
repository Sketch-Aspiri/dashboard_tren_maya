<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Proveedor del fondo rotatorio con su tasa de IVA. Lo mantiene la
 * jefatura; las partidas lo referencian por nombre.
 */
class Proveedor extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'proveedores';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'nombre',
        'rfc',
        'iva',
        'retiene_isr',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'iva' => 'integer',
            'retiene_isr' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->fillable)
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('proveedor');
    }
}
