<?php

namespace App\Services\Secciones;

use App\Models\Seccion;
use App\Models\SeccionArchivo;
use App\Models\SeccionFila;
use App\Models\SeccionOrigen;
use App\Models\User;
use App\Services\Nube\Datos\ArchivoRemoto;
use App\Support\AlcanceEstacion;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Guarda documentos de una sección en el disco privado. La ruta la genera
 * el servidor (UUID): el nombre del cliente nunca forma parte de la ruta.
 */
final class ArchivoService
{
    public function __construct(private readonly ExtractorDocumentos $extractor) {}

    public function guardar(Seccion $seccion, UploadedFile $archivo, User $usuario, ?SeccionFila $fila = null): SeccionArchivo
    {
        $extension = Str::lower($archivo->getClientOriginalExtension());
        $ruta = $archivo->storeAs("secciones/{$seccion->id}", Str::uuid()->toString().'.'.$extension, $this->disco());

        $registro = new SeccionArchivo([
            'seccion_fila_id' => $fila?->id,
            'nombre_original' => Str::limit($archivo->getClientOriginalName(), 250, ''),
            'ruta' => $ruta,
            'mime' => (string) $archivo->getMimeType(),
            'extension' => $extension,
            'tamano' => (int) $archivo->getSize(),
            'texto_extraido' => $this->puedeLeerse($archivo->getSize())
                ? $this->extractor->texto($this->rutaAbsolutaDe($ruta), $extension)
                : null,
        ]);

        // Estación del documento: la de su fila; si no, la de la cuenta Estación que lo sube; si no, ninguna.
        $registro->forceFill([
            'seccion_id' => $seccion->id,
            'subido_por' => $usuario->id,
            'estacion_id' => $fila?->estacion_id ?? AlcanceEstacion::estacionId($usuario),
        ])->save();

        return $registro;
    }

    /**
     * Guarda un archivo ya descargado a una ruta temporal (sincronización con
     * la nube). Queda sin estación: solo lo ve la zona hasta que se etiquete.
     * La bitácora es la del motor (documento_agregado_nube), no la del modelo.
     */
    public function guardarDesdeRuta(Seccion $seccion, string $ruta, string $nombre, ?User $usuario, ?SeccionOrigen $origen, ArchivoRemoto $remoto): SeccionArchivo
    {
        $extension = Str::lower(pathinfo($nombre, PATHINFO_EXTENSION));
        $tamano = (int) filesize($ruta);
        $guardada = (string) Storage::disk($this->disco())->putFileAs("secciones/{$seccion->id}", new File($ruta), Str::uuid()->toString().'.'.$extension);

        $registro = new SeccionArchivo([
            'nombre_original' => Str::limit($nombre, 250, ''),
            'ruta' => $guardada,
            'mime' => (string) (new File($ruta))->getMimeType(),
            'extension' => $extension,
            'tamano' => $tamano,
            'texto_extraido' => $this->puedeLeerse($tamano) ? $this->extractor->texto($this->rutaAbsolutaDe($guardada), $extension) : null,
        ]);

        $registro->disableLogging();
        $registro->forceFill([
            'seccion_id' => $seccion->id,
            'subido_por' => $usuario?->id ?? $origen?->creado_por,
            'seccion_origen_id' => $origen?->id,
            'contenido_hash' => hash_file('sha256', $ruta),
        ])->forceFill($this->atributosRemotos($remoto))->save();

        return $registro;
    }

    /**
     * Identidad del archivo en la nube (la comparan CalculadorDiferencias y el motor).
     *
     * @return array<string, mixed>
     */
    public function atributosRemotos(ArchivoRemoto $remoto): array
    {
        return [
            'remoto_id' => Str::limit($remoto->id, 250, ''),
            'remoto_ruta' => Str::limit($remoto->rutaRelativa, 990, ''),
            'remoto_version' => $remoto->version === null ? null : Str::limit($remoto->version, 250, ''),
            'remoto_modificado_en' => $remoto->modificadoEn,
        ];
    }

    public function eliminar(SeccionArchivo $archivo): void
    {
        // Un documento borrado no se sigue sincronizando: su origen se da de baja con él.
        $archivo->origen?->delete();
        $archivo->delete();
    }

    public function rutaAbsoluta(SeccionArchivo $archivo): string
    {
        return $this->rutaAbsolutaDe($archivo->ruta);
    }

    public function puedeLeerse(int|false $tamanoBytes): bool
    {
        return $tamanoBytes !== false && $tamanoBytes <= (int) config('secciones.max_lectura_kb') * 1024;
    }

    public function disco(): string
    {
        return (string) config('secciones.disco');
    }

    private function rutaAbsolutaDe(string $ruta): string
    {
        return Storage::disk($this->disco())->path($ruta);
    }
}
