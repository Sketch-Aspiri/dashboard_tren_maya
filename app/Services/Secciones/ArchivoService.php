<?php

namespace App\Services\Secciones;

use App\Models\Seccion;
use App\Models\SeccionArchivo;
use App\Models\SeccionFila;
use App\Models\User;
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

        $registro->forceFill(['seccion_id' => $seccion->id, 'subido_por' => $usuario->id])->save();

        return $registro;
    }

    public function eliminar(SeccionArchivo $archivo): void
    {
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
