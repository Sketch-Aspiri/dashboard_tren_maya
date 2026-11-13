<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Catálogo de artículos de papelería (hoja CATALOGOS del formato oficial).
 * Lo mantiene la jefatura; las estaciones solo lo consultan al capturar.
 */
class CatalogoPapeleria extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'catalogo_papeleria';

    /**
     * @var list<string>
     */
    protected $fillable = ['descripcion', 'activo'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->fillable)
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('catalogo_papeleria');
    }
}
