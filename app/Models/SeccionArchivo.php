<?php

namespace App\Models;

use App\Services\Secciones\VisibilidadSecciones;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
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
        // Columna heredada (ya sin uso, se elimina en una migración posterior): el enlace compartido no se serializa.
        'origen_url',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tamano' => 'integer',
            'remoto_modificado_en' => 'datetime',
        ];
    }

    public function estaVinculadoANube(): bool
    {
        return $this->seccion_origen_id !== null;
    }

    /**
     * Copia de un archivo de una carpeta o archivo vinculado a la nube: lo
     * administra la sincronización, no se borra ni se vuelve a vincular a mano.
     */
    public function esEspejo(): bool
    {
        return $this->seccion_origen_id !== null && $this->remoto_id !== null;
    }

    /**
     * Carpeta (relativa a la raíz del origen) donde vive en la nube; '' = raíz.
     */
    public function carpetaRemota(): string
    {
        $ruta = (string) $this->remoto_ruta;

        return str_contains($ruta, '/') ? substr($ruta, 0, (int) strrpos($ruta, '/')) : '';
    }

    /**
     * @return BelongsTo<SeccionOrigen, $this>
     */
    public function origen(): BelongsTo
    {
        return $this->belongsTo(SeccionOrigen::class, 'seccion_origen_id');
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
     * @return BelongsTo<Estacion, $this>
     */
    public function estacion(): BelongsTo
    {
        return $this->belongsTo(Estacion::class);
    }

    /**
     * Solo los documentos que el usuario puede ver: la zona ve todos; una
     * cuenta Estación, los de las secciones que tiene permitidas completas y
     * los adjuntos de filas de las hojas que tiene permitidas.
     *
     * @param  Builder<SeccionArchivo>  $consulta
     */
    public function scopeVisiblePara(Builder $consulta, User $usuario): void
    {
        $alcance = app(VisibilidadSecciones::class)->alcancePara($usuario);

        if ($alcance === null) {
            return;
        }

        $consulta->where(function (Builder $q) use ($alcance): void {
            $q->whereIn($this->qualifyColumn('seccion_id'), $alcance['secciones']);

            if ($alcance['tablas'] !== []) {
                $q->orWhereIn($this->qualifyColumn('seccion_fila_id'), DB::table('seccion_filas')
                    ->select('id')
                    ->whereIn('seccion_tabla_id', $alcance['tablas'])
                    ->whereNull('deleted_at'));
            }
        });
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
            ->logOnly(['seccion_id', 'seccion_fila_id', 'estacion_id', 'nombre_original', 'extension', 'tamano'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('seccion');
    }
}
