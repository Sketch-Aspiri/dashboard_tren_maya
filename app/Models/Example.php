<?php

namespace App\Models;

use App\Enums\ExampleStatus;
use Database\Factories\ExampleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Disposable/adaptable reference CRUD model (see CLAUDE.md). Fields are
 * deliberately generic dummy data until the real data model is provided by
 * the Jefe de Zona — swap fields here (and in the migration/requests) when
 * it lands, without restructuring the controller/policy/request layer.
 */
class Example extends Model
{
    /** @use HasFactory<ExampleFactory> */
    use HasFactory, LogsActivity;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'value',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ExampleStatus::class,
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'value', 'status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('example');
    }
}
