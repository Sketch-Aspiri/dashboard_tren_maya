<?php

namespace App\Services;

use App\Models\Estacion;
use App\Models\OficioComision;
use App\Models\User;
use App\Services\Documentos\ConversionPdfException;
use App\Services\Documentos\ConvertidorPdf;
use App\Services\Documentos\DirectorioTemporal;
use App\Services\Documentos\RasterizadorPdf;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Oficios de comisión de las estaciones: se suben (pdf, word o imagen) y se
 * convierten en imágenes de página para anexarlas al final del control de
 * asistencia (Word y PDF) de la estación y al de la zona.
 */
final class OficiosComisionService
{
    private const IMAGENES = ['jpg', 'png'];

    public function __construct(
        private readonly ConvertidorPdf $convertidor,
        private readonly RasterizadorPdf $rasterizador,
    ) {}

    public function disco(): Filesystem
    {
        return Storage::disk(config('asistencia_documentos.disk'));
    }

    /**
     * @param  list<UploadedFile>  $archivos
     * @return Collection<int, OficioComision>
     */
    public function almacenar(Estacion $estacion, array $archivos, ?string $vigenteHasta, User $actor): Collection
    {
        $creados = collect();

        foreach ($archivos as $archivo) {
            $extension = Str::lower($archivo->getClientOriginalExtension() ?: (string) $archivo->guessExtension());
            $extension = $extension === 'jpeg' ? 'jpg' : $extension;

            if (! in_array($extension, ['pdf', 'doc', 'docx', 'jpg', 'png'], true)) {
                throw new DomainException(__('Formato no admitido: solo PDF, Word (.doc/.docx) o imagen (JPG/PNG).'));
            }

            // El nombre en disco lo genera el servidor: nunca sale del archivo subido.
            $ruta = sprintf('%s/oficios-comision/%d/%s.%s', config('asistencia_documentos.directorio'), $estacion->id, Str::random(24), $extension);
            $this->disco()->put($ruta, (string) file_get_contents($archivo->getRealPath()));

            $creados->push(OficioComision::create([
                'estacion_id' => $estacion->id,
                'nombre_original' => Str::limit(preg_replace('/[\x00-\x1F]/u', '', $archivo->getClientOriginalName()) ?: 'oficio.'.$extension, 190, ''),
                'ruta' => $ruta,
                'extension' => $extension,
                'tamano' => (int) $archivo->getSize(),
                'vigente_hasta' => $vigenteHasta,
                'subido_por' => $actor->id,
            ]));
        }

        return $creados;
    }

    public function eliminar(OficioComision $oficio): void
    {
        $this->disco()->delete($oficio->ruta);
        $oficio->delete();
    }

    /**
     * Todos los oficios de una estación (vigentes o vencidos), del más nuevo al más viejo.
     *
     * @return Collection<int, OficioComision>
     */
    public function listado(Estacion $estacion): Collection
    {
        return OficioComision::query()->where('estacion_id', $estacion->id)->latest('id')->get();
    }

    /**
     * Los que van anexos al control de esa fecha, en el orden del oficio de zona
     * (estaciones en su orden y el Edificio Zonal al final; dentro de cada una, por subida).
     *
     * @return Collection<int, OficioComision>
     */
    public function vigentes(?Estacion $estacion, CarbonInterface $fecha): Collection
    {
        return OficioComision::query()
            ->with('estacion')
            ->join('estaciones', 'estaciones.id', '=', 'oficios_comision.estacion_id')
            ->when($estacion !== null, fn ($q) => $q->where('oficios_comision.estacion_id', $estacion->id))
            ->where(fn ($q) => $q->whereNull('oficios_comision.vigente_hasta')->orWhereDate('oficios_comision.vigente_hasta', '>=', $fecha->toDateString()))
            ->orderByDesc('estaciones.is_operativa')
            ->orderBy('estaciones.orden')
            ->orderBy('oficios_comision.id')
            ->select('oficios_comision.*')
            ->get();
    }

    /**
     * Cuántos oficios vigentes aporta cada estación (para el resumen del control de zona).
     *
     * @return Collection<string, int> nombre de estación => cantidad
     */
    public function resumenPorEstacion(?Estacion $estacion, CarbonInterface $fecha): Collection
    {
        return $this->vigentes($estacion, $fecha)->groupBy(fn (OficioComision $o) => $o->estacion->nombre)->map->count();
    }

    /**
     * Instante del último alta/baja de oficios (null si nunca hubo): si es posterior
     * al control generado, ese control ya no incluye los anexos actuales.
     */
    public function ultimoCambio(?int $estacionId): ?string
    {
        return DB::table('oficios_comision')
            ->when($estacionId !== null, fn ($q) => $q->where('estacion_id', $estacionId))
            ->max('updated_at');
    }

    /**
     * Imágenes de página de todos los oficios, en orden. Quien llama debe
     * borrarlas con limpiar() cuando termine de armar el documento.
     *
     * @param  Collection<int, OficioComision>  $oficios
     * @return list<string> rutas absolutas (JPG)
     *
     * @throws ConversionPdfException cuando un archivo no se puede leer
     */
    public function paginas(Collection $oficios): array
    {
        if ($oficios->isEmpty()) {
            return [];
        }

        $max = (int) config('asistencia_documentos.oficios_comision.max_paginas');
        $trabajo = DirectorioTemporal::ruta().DIRECTORY_SEPARATOR.'anexos_'.Str::uuid()->toString();
        File::ensureDirectoryExists($trabajo, 0700, true);
        $paginas = [];

        try {
            foreach ($oficios->values() as $n => $oficio) {
                if (! $this->disco()->exists($oficio->ruta)) {
                    throw new ConversionPdfException(__('Falta el archivo del oficio «:nombre»; vuelve a subirlo.', ['nombre' => $oficio->nombre_original]));
                }

                $origen = $trabajo.DIRECTORY_SEPARATOR.$n.'.'.$oficio->extension;
                file_put_contents($origen, $this->disco()->get($oficio->ruta));

                try {
                    $paginas = [...$paginas, ...$this->paginasDe($origen, $oficio->extension, $trabajo.DIRECTORY_SEPARATOR.'p'.$n, $max)];
                } catch (ConversionPdfException $e) {
                    throw new ConversionPdfException(__('No se pudo anexar el oficio «:nombre»: :motivo', ['nombre' => $oficio->nombre_original, 'motivo' => $e->getMessage()]), 0, $e);
                }
            }
        } catch (\Throwable $e) {
            File::deleteDirectory($trabajo);

            throw $e;
        }

        return $paginas;
    }

    /**
     * @param  list<string>  $paginas
     */
    public function limpiar(array $paginas): void
    {
        foreach (array_unique(array_map('dirname', $paginas)) as $carpeta) {
            File::deleteDirectory($carpeta);
        }
    }

    /**
     * @return list<string>
     */
    private function paginasDe(string $origen, string $extension, string $prefijo, int $max): array
    {
        if (in_array($extension, self::IMAGENES, true)) {
            return [$this->imagenAJpg($origen, $prefijo.'-001.jpg')];
        }

        if ($extension === 'pdf') {
            return $this->rasterizador->paginas($origen, $prefijo, $max);
        }

        // Word: LibreOffice lo convierte a PDF y de ahí a imágenes.
        $pdf = $prefijo.'.pdf';
        $this->convertidor->convertir($origen, $pdf);

        return $this->rasterizador->paginas($pdf, $prefijo, $max);
    }

    /**
     * Re-codifica con GD (sin metadatos ni cargas ocultas), respeta la rotación
     * EXIF, aplana la transparencia sobre blanco y limita el tamaño.
     */
    private function imagenAJpg(string $origen, string $destino): string
    {
        $medidas = @getimagesize($origen);
        $tope = (int) config('asistencia_documentos.oficios_comision.max_lado_px');

        if ($medidas === false || ! in_array($medidas[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            throw new ConversionPdfException(__('no es una imagen válida.'));
        }

        // Antes de decodificar: unos KB de PNG pueden expandirse a gigas de píxeles.
        if ($medidas[0] * $medidas[1] > 50_000_000) {
            throw new ConversionPdfException(__('la imagen es demasiado grande.'));
        }

        $imagen = @imagecreatefromstring((string) file_get_contents($origen));

        if ($imagen === false) {
            throw new ConversionPdfException(__('no se pudo leer la imagen.'));
        }

        if ($medidas[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $giro = @exif_read_data($origen)['Orientation'] ?? 1;
            $grados = [3 => 180, 6 => -90, 8 => 90][$giro] ?? 0;
            if ($grados !== 0 && ($girada = imagerotate($imagen, $grados, 0)) !== false) {
                $imagen = $girada;
            }
        }

        $ancho = imagesx($imagen);
        $alto = imagesy($imagen);
        $escala = min(1, $tope / max($ancho, $alto));
        $nuevoAncho = max(1, (int) round($ancho * $escala));
        $nuevoAlto = max(1, (int) round($alto * $escala));

        $lienzo = imagecreatetruecolor($nuevoAncho, $nuevoAlto);
        imagefill($lienzo, 0, 0, imagecolorallocate($lienzo, 255, 255, 255));
        imagecopyresampled($lienzo, $imagen, 0, 0, 0, 0, $nuevoAncho, $nuevoAlto, $ancho, $alto);

        if (! imagejpeg($lienzo, $destino, 88)) {
            throw new ConversionPdfException(__('no se pudo procesar la imagen.'));
        }

        return $destino;
    }
}
