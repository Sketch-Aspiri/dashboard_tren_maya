<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Documento de una sección (o adjunto de una fila). El archivo físico vive
 * en el disco privado ("local"); solo se descarga por la ruta autorizada
 * secciones.archivos.show.
 */
class SeccionArchivo extends Model
{
    use LogsActivity, SoftDeletes;

    protected $table = 'seccion_archivos';

    /**
     * Extensiones que el navegador puede mostrar en línea (vista previa) y
     * su Content-Type: fuente única de ambas reglas.
     *
     * @var array<string, string>
     */
    private const MIME_EN_LINEA = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
    ];

    /**
     * Todo lo asigna el servidor (ArchivoSeccionService); nada viene del
     * cliente salvo el nombre original, que solo se muestra.
     *
     * @var list<string>
     */
    protected $fillable = [
        'seccion_fila_id',
        'nombre_original',
        'ruta',
        'mime',
        'extension',
        'tamano',
        'texto_extraido',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'ruta',
        'texto_extraido',
        'origen_url',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tamano' => 'integer',
            'sincronizacion_activa' => 'boolean',
            'sincronizado_en' => 'datetime',
        ];
    }

    public function estaVinculadoANube(): bool
    {
        return $this->origen_url !== null;
    }

    /**
     * @return BelongsTo<Seccion, $this>
     */
    public function seccion(): BelongsTo
    {
        return $this->belongsTo(Seccion::class);
    }

    /**
     * @return BelongsTo<SeccionFila, $this>
     */
    public function fila(): BelongsTo
    {
        return $this->belongsTo(SeccionFila::class, 'seccion_fila_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subido_por');
    }

    public function admiteVistaPrevia(): bool
    {
        return $this->mimeEnLinea() !== null;
    }

    /**
     * Content-Type con el que se sirve en línea. Lo decide el servidor por la
     * extensión validada al subir, nunca el valor guardado ni el del cliente.
     */
    public function mimeEnLinea(): ?string
    {
        return self::MIME_EN_LINEA[$this->extension] ?? null;
    }

    public function tamanoLegible(): string
    {
        $bytes = $this->tamano;

        foreach (['B', 'KB', 'MB'] as $unidad) {
            if ($bytes < 1024) {
                return ($unidad === 'B' ? $bytes : number_format($bytes, 1)).' '.$unidad;
            }

            $bytes /= 1024;
        }

        return number_format($bytes, 1).' GB';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['seccion_id', 'seccion_fila_id', 'nombre_original', 'extension', 'tamano'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('seccion');
    }
}
