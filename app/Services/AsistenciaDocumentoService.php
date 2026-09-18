<?php

namespace App\Services;

use App\Enums\EmpleadoEstatus;
use App\Enums\TipoDocumentoAsistencia;
use App\Models\DocumentoAsistencia;
use App\Models\Empleado;
use App\Models\Estacion;
use App\Models\User;
use App\Services\Documentos\AsistenciaDocumentoDataBuilder;
use App\Services\Documentos\ConvertidorPdf;
use App\Services\Documentos\DirectorioTemporal;
use App\Services\Documentos\GeneradorOficioEstacion;
use App\Services\Documentos\GeneradorOficioZona;
use App\Services\Documentos\MetadatosOficio;
use App\Services\Documentos\NumeroOficioService;
use Carbon\CarbonInterface;
use Closure;
use DomainException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Generates, stores and looks up the attendance oficios (.docx + .pdf).
 *
 * Both files are produced first and only then persisted, so a failed PDF
 * conversion never leaves a half-updated document. Generating the same
 * oficio again (same tipo + estación + fecha) overwrites its files and
 * keeps its row — and therefore its folio.
 */
final class AsistenciaDocumentoService
{
    public function __construct(
        private readonly AsistenciaCapturaService $captura,
        private readonly AsistenciaDocumentoDataBuilder $builder,
        private readonly GeneradorOficioEstacion $generadorEstacion,
        private readonly GeneradorOficioZona $generadorZona,
        private readonly ConvertidorPdf $convertidor,
        private readonly NumeroOficioService $numeros,
    ) {}

    /**
     * @throws DomainException when the station has not captured everything yet
     */
    public function generarEstacion(Estacion $estacion, CarbonInterface $fecha, MetadatosOficio $meta, User $actor): DocumentoAsistencia
    {
        if (! $this->captura->estacionCapturada($estacion, $fecha)) {
            throw new DomainException(__('La estación aún no captura toda su asistencia de este día.'));
        }

        $datosAl = now();
        $data = $this->builder->paraEstacion($estacion, $fecha);

        return $this->producir(
            TipoDocumentoAsistencia::Estacion,
            $estacion->id,
            $fecha,
            $datosAl,
            $meta,
            false,
            $actor,
            fn (string $docx) => $this->generadorEstacion->generar($data, $meta, $docx),
        );
    }

    /**
     * @param  string  $firmanteClave  key of asistencia_documentos.zona.firmantes
     *
     * @throws DomainException when a station is still pending or the signer is unknown
     */
    public function generarZona(CarbonInterface $fecha, string $numeroOficio, string $firmanteClave, User $actor): DocumentoAsistencia
    {
        $pendientes = $this->captura->estacionesPendientes($fecha);

        if ($pendientes->isNotEmpty()) {
            throw new DomainException(__('Faltan por capturar: :estaciones.', [
                'estaciones' => $pendientes->pluck('nombre')->implode(', '),
            ]));
        }

        $firmante = config('asistencia_documentos.zona.firmantes.'.$firmanteClave);

        if (! is_array($firmante)) {
            throw new DomainException(__('El firmante seleccionado no es válido.'));
        }

        $meta = new MetadatosOficio(
            $numeroOficio,
            $firmante['cargo'],
            $firmante['nombre'],
            $firmante['iniciales'],
            $firmante['suplencia'] ? (string) config('asistencia_documentos.zona.texto_suplencia') : '',
        );
        $datosAl = now();
        $data = $this->builder->paraZona($fecha);

        return $this->producir(
            TipoDocumentoAsistencia::Zona,
            null,
            $fecha,
            $datosAl,
            $meta,
            (bool) $firmante['suplencia'],
            $actor,
            fn (string $docx) => $this->generadorZona->generar($data, $meta, $docx),
        );
    }

    public function documentoDeZona(CarbonInterface $fecha): ?DocumentoAsistencia
    {
        return DocumentoAsistencia::query()
            ->where('tipo', TipoDocumentoAsistencia::Zona->value)
            ->whereNull('estacion_id')
            ->whereDate('fecha', $fecha)
            ->first();
    }

    /**
     * Everything the zone board's "Oficio de zona" card shows.
     *
     * @return array{pendientes: Collection<int, Estacion>, documento: ?DocumentoAsistencia, desactualizado: bool, numero: string, firmante: string}
     */
    public function panelZona(CarbonInterface $fecha): array
    {
        $documento = $this->documentoDeZona($fecha);

        return [
            'pendientes' => $this->captura->estacionesPendientes($fecha),
            'documento' => $documento,
            'desactualizado' => $documento !== null && $this->estaDesactualizado($documento),
            'numero' => $documento?->numero_oficio ?? $this->numeros->sugerirParaZona(),
            'firmante' => $documento?->es_suplencia ? 'subgerente' : 'director',
        ];
    }

    /**
     * Existing oficio for the estación+fecha, if one was already generated.
     */
    public function documentoDeEstacion(Estacion $estacion, CarbonInterface $fecha): ?DocumentoAsistencia
    {
        return DocumentoAsistencia::query()
            ->where('tipo', TipoDocumentoAsistencia::Estacion->value)
            ->where('estacion_id', $estacion->id)
            ->whereDate('fecha', $fecha)
            ->first();
    }

    /**
     * Whether attendance was (re)captured after the oficio was generated,
     * i.e. the stored files no longer match the data.
     */
    public function estaDesactualizado(DocumentoAsistencia $documento): bool
    {
        // datos_al is the instant BEFORE the data was read for the files, so an
        // edit made while the oficio was being generated still counts as newer.
        $datosAl = ($documento->datos_al ?? $documento->updated_at)->toDateTimeString();

        // The station oficio only prints daily statuses; the zone oficio also
        // prints the commissioned people.
        $tablas = $documento->estacion_id === null
            ? ['registros_diarios', 'comisionados_fuera', 'comisionados_visitantes']
            : ['registros_diarios'];

        foreach ($tablas as $tabla) {
            $ultimoCambio = DB::table($tabla)
                ->whereDate('fecha', $documento->fecha)
                ->when(
                    $documento->estacion_id !== null,
                    fn ($query) => $query->where('estacion_id', $documento->estacion_id),
                )
                ->max('updated_at');

            if ($ultimoCambio !== null && $ultimoCambio > $datosAl) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prefills the signer of a station oficio from the roster: the active
     * "Gerencia de Estación …" employee of that station. Editable by the
     * person generating the document.
     *
     * @return array{cargo: string, nombre: string}
     */
    public function firmanteSugeridoEstacion(Estacion $estacion): array
    {
        $gerente = Empleado::query()
            ->where('estacion_id', $estacion->id)
            ->where('estatus', EmpleadoEstatus::Activo->value)
            ->where('puesto', 'like', 'Gerencia de Estaci%')
            ->orderBy('orden_oficio')
            ->first();

        return [
            'cargo' => 'Gerente de Estación '.$estacion->nombre,
            'nombre' => $gerente?->nombre_completo ?? '',
        ];
    }

    /**
     * Everything the capture screen's "Oficio de asistencia" card shows:
     * whether the station finished capturing, the existing oficio (if any)
     * and the prefilled folio/signer for generating or regenerating it.
     *
     * @return array{capturada: bool, documento: ?DocumentoAsistencia, desactualizado: bool, numero: string, firmante_cargo: string, firmante_nombre: string}
     */
    public function panelEstacion(Estacion $estacion, CarbonInterface $fecha): array
    {
        $documento = $this->documentoDeEstacion($estacion, $fecha);
        $firmante = $this->firmanteSugeridoEstacion($estacion);

        return [
            'capturada' => $this->captura->estacionCapturada($estacion, $fecha),
            'documento' => $documento,
            'desactualizado' => $documento !== null && $this->estaDesactualizado($documento),
            'numero' => $documento?->numero_oficio ?? $this->numeros->sugerirParaEstacion($estacion, $fecha),
            'firmante_cargo' => $documento?->firmante_cargo ?? $firmante['cargo'],
            'firmante_nombre' => $documento?->firmante_nombre ?? $firmante['nombre'],
        ];
    }

    public function disco(): Filesystem
    {
        return Storage::disk(config('asistencia_documentos.disk'));
    }

    /**
     * @param  Closure(string): void  $llenarDocx  writes the .docx at the given absolute path
     */
    private function producir(
        TipoDocumentoAsistencia $tipo,
        ?int $estacionId,
        CarbonInterface $fecha,
        CarbonInterface $datosAl,
        MetadatosOficio $meta,
        bool $esSuplencia,
        User $actor,
        Closure $llenarDocx,
    ): DocumentoAsistencia {
        // One generation at a time per oficio: the unique index cannot stop
        // duplicate zone rows (estacion_id is NULL there) and two racing
        // requests would also leave orphan files.
        $bloqueo = Cache::lock(sprintf('oficio:%s:%s:%s', $tipo->value, $estacionId ?? 'zona', $fecha->toDateString()), 300);

        if (! $bloqueo->get()) {
            throw new DomainException(__('Este oficio ya se está generando. Espera unos segundos e inténtalo de nuevo.'));
        }

        $base = DirectorioTemporal::ruta().DIRECTORY_SEPARATOR.'oficio_'.Str::uuid()->toString();
        $docxTemporal = $base.'.docx';
        $pdfTemporal = $base.'.pdf';

        try {
            $llenarDocx($docxTemporal);
            @chmod($docxTemporal, 0600);
            $this->convertidor->convertir($docxTemporal, $pdfTemporal);

            return $this->persistir(
                $tipo, $estacionId, $fecha, $datosAl, $meta, $esSuplencia, $actor,
                (string) file_get_contents($docxTemporal), (string) file_get_contents($pdfTemporal),
            );
        } finally {
            foreach ([$docxTemporal, $pdfTemporal] as $temporal) {
                if (is_file($temporal)) {
                    unlink($temporal);
                }
            }
            $bloqueo->release();
        }
    }

    /**
     * Upserts the row and then writes both files. The row goes first: if the
     * folio collides (unique index), nothing on disk has been touched yet. A
     * regeneration keeps the row (and folio) and is logged explicitly: with
     * the same folio and signer nothing on the row is dirty, so the model's
     * automatic activity log would otherwise record nothing while the
     * official files change.
     */
    private function persistir(
        TipoDocumentoAsistencia $tipo,
        ?int $estacionId,
        CarbonInterface $fecha,
        CarbonInterface $datosAl,
        MetadatosOficio $meta,
        bool $esSuplencia,
        User $actor,
        string $docx,
        string $pdf,
    ): DocumentoAsistencia {
        $rutasNuevas = [];

        try {
            return DB::transaction(function () use ($tipo, $estacionId, $fecha, $datosAl, $meta, $esSuplencia, $actor, $docx, $pdf, &$rutasNuevas) {
                $documento = $this->documentoExistente($tipo, $estacionId, $fecha) ?? new DocumentoAsistencia;
                $esRegeneracion = $documento->exists;
                $folioAnterior = $documento->numero_oficio;
                $rutaBase = $esRegeneracion
                    ? preg_replace('/\.docx$/', '', $documento->docx_path)
                    : $this->nuevaRutaBase($tipo, $fecha);

                $documento->fill([
                    'tipo' => $tipo->value,
                    'estacion_id' => $estacionId,
                    'fecha' => $fecha,
                    'numero_oficio' => $meta->numeroOficio,
                    'firmante_cargo' => $meta->firmanteCargo,
                    'firmante_nombre' => $meta->firmanteNombre,
                    'es_suplencia' => $esSuplencia,
                    'docx_path' => $rutaBase.'.docx',
                    'pdf_path' => $rutaBase.'.pdf',
                    'datos_al' => $datosAl,
                    'generado_por' => $actor->id,
                ])->save();

                // Same folio + signer leaves nothing dirty: the oficio was still
                // regenerated, so updated_at must move.
                $documento->touch();

                if (! $esRegeneracion) {
                    $rutasNuevas = [$documento->docx_path, $documento->pdf_path];
                }

                $this->escribirArchivos($documento, $docx, $pdf);

                if ($esRegeneracion) {
                    activity('documento_asistencia')
                        ->performedOn($documento)
                        ->causedBy($actor)
                        ->withProperties(['numero_oficio_anterior' => $folioAnterior, 'numero_oficio' => $meta->numeroOficio])
                        ->log('regenerado');
                }

                return $documento;
            });
        } catch (Throwable $e) {
            // A brand-new oficio that failed to persist must not leave files with personal data behind.
            foreach ($rutasNuevas as $ruta) {
                $this->disco()->delete($ruta);
            }

            throw $e;
        }
    }

    private function documentoExistente(TipoDocumentoAsistencia $tipo, ?int $estacionId, CarbonInterface $fecha): ?DocumentoAsistencia
    {
        return DocumentoAsistencia::query()
            ->where('tipo', $tipo->value)
            ->when(
                $estacionId === null,
                fn ($query) => $query->whereNull('estacion_id'),
                fn ($query) => $query->where('estacion_id', $estacionId),
            )
            ->whereDate('fecha', $fecha)
            ->lockForUpdate()
            ->first();
    }

    /**
     * File names are always server-generated — never derived from user input.
     */
    private function nuevaRutaBase(TipoDocumentoAsistencia $tipo, CarbonInterface $fecha): string
    {
        return sprintf(
            '%s/%s/oficio-%s-%s-%s',
            config('asistencia_documentos.directorio'),
            $fecha->format('Y/m'),
            $tipo->value,
            $fecha->toDateString(),
            Str::random(16),
        );
    }

    /**
     * @throws RuntimeException when the disk refuses a write (put() returns false)
     */
    private function escribirArchivos(DocumentoAsistencia $documento, string $docx, string $pdf): void
    {
        $disco = $this->disco();

        if (! $disco->put($documento->docx_path, $docx) || ! $disco->put($documento->pdf_path, $pdf)) {
            throw new RuntimeException('No se pudieron guardar los archivos del oficio.');
        }
    }
}
