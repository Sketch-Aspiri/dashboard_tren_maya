<?php

namespace App\Models;

use App\Enums\TipoDocumentoAsistencia;
use Database\Factories\DocumentoAsistenciaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A generated attendance oficio (.docx + .pdf) for one estación+fecha, or
 * for the whole zone on one fecha. The files live on a private disk and are
 * only reachable through AsistenciaDocumentoController.
 */
class DocumentoAsistencia extends Model
{
    /** @use HasFactory<DocumentoAsistenciaFactory> */
    use HasFactory, LogsActivity;

    /**
     * Eloquent's English pluralizer would guess "documento_asistencias".
     */
    protected $table = 'documentos_asistencia';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tipo',
        'estacion_id',
        'fecha',
        'numero_oficio',
        'firmante_cargo',
        'firmante_nombre',
        'es_suplencia',
        'datos_al',
        'docx_path',
        'pdf_path',
        'generado_por',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo' => TipoDocumentoAsistencia::class,
            'fecha' => 'date',
            'es_suplencia' => 'boolean',
            'datos_al' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['tipo', 'estacion_id', 'fecha', 'numero_oficio', 'firmante_cargo', 'firmante_nombre', 'es_suplencia', 'generado_por'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('documento_asistencia');
    }

    /**
     * @return BelongsTo<Estacion, $this>
     */
    public function estacion(): BelongsTo
    {
        return $this->belongsTo(Estacion::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function generadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generado_por');
    }
}
