<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Foto de evidencia de un registro mensual de PTAR (disco privado `local`). */
class PtarEvidencia extends Model
{
    use LogsActivity;

    public const CAPTURA = 'captura';

    public const IMPORTACION = 'importacion';

    protected $table = 'ptar_evidencias';

    /** @var list<string> */
    protected $fillable = ['registro_id', 'ruta', 'nombre', 'pie', 'origen', 'capturo_id'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['registro_id', 'nombre', 'pie', 'origen'])->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('ptar');
    }

    /** @return BelongsTo<PtarRegistro, $this> */
    public function registro(): BelongsTo
    {
        return $this->belongsTo(PtarRegistro::class, 'registro_id');
    }
}
