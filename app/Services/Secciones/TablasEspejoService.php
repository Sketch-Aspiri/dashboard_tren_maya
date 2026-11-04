<?php

namespace App\Services\Secciones;

use App\Enums\EstadoImportacion;
use App\Enums\EstadoOrigen;
use App\Enums\ModoImportacion;
use App\Enums\TipoColumna;
use App\Models\SeccionArchivo;
use App\Models\SeccionFila;
use App\Models\SeccionImportacion;
use App\Models\SeccionMapeo;
use App\Models\SeccionOrigen;
use App\Models\SeccionTabla;
use App\Models\User;
use App\Support\Texto;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Tablas espejo: los Excel/CSV de un origen de nube (alimenta_tablas) mantienen
 * tablas de solo lectura siempre iguales a la nube.
 *
 * - Primera vez (el origen no tiene ningún mapeo): se deja una vista previa
 *   (SeccionImportacion origen=nube) y el origen queda en `pendiente_mapeo`
 *   hasta que el Administrador confirma el mapeo una vez (MapeoEspejoService).
 * - Después: cada hoja con mapeo se aplica en ModoImportacion::Espejo; una hoja
 *   o Excel nuevo en un origen ya mapeado crea su tabla sola con valores sugeridos.
 * - Un Excel ilegible nunca toca la tabla; una hoja vacía o un cambio masivo
 *   (se archivaría más de espejo_max_archivado_pct % de las filas) se retiene.
 */
final class TablasEspejoService
{
    public const EXTENSIONES = ['xlsx', 'xls', 'csv'];

    public const RETENIDO_VACIA = 'hoja_vacia';

    public const RETENIDO_MASIVO = 'cambio_masivo';

    public const RETENIDO_LLAVE = 'llave_perdida';

    private const MAX_NOMBRE = 160;

    public function __construct(
        private readonly ExtractorDocumentos $extractor,
        private readonly ArchivoService $archivos,
        private readonly ImportacionService $importaciones,
        private readonly LibroImportacionService $libros,
        private readonly PreparadorTabla $preparador,
        private readonly PlanificadorImportacion $planificador,
    ) {}

    /**
     * ¿Este documento del origen alimenta tablas espejo? Los documentos que ya
     * se cargaron a mano (sin mapeo) siguen el camino de siempre.
     */
    public function atiende(SeccionOrigen $origen, SeccionArchivo $archivo): bool
    {
        if (! $this->esCandidato($origen, $archivo)) {
            return false;
        }

        return SeccionMapeo::query()->where('seccion_archivo_id', $archivo->id)->exists()
            || SeccionImportacion::query()
                ->where('seccion_archivo_id', $archivo->id)
                ->where('origen', '!=', SeccionImportacion::ORIGEN_NUBE)
                ->where('estado', EstadoImportacion::Aplicada->value)
                ->doesntExist();
    }

    /**
     * Pone al día las tablas de un documento del origen. Devuelve un aviso (no
     * un error) si algo quedó sin aplicar: el documento ya se guardó.
     */
    public function sincronizar(SeccionOrigen $origen, SeccionArchivo $archivo, ?User $usuario): ?string
    {
        try {
            return $this->procesar($origen, $archivo, $usuario);
        } catch (Throwable $e) {
            report($e);

            return __('No se pudieron actualizar las tablas de «:doc»; el documento sí se guardó. Revisa el registro del servidor.', ['doc' => $archivo->nombre_original]);
        }
    }

    /**
     * Estado del origen según tenga vistas previas de mapeo sin confirmar.
     */
    public function refrescarEstado(SeccionOrigen $origen): void
    {
        if (! in_array($origen->estado, [EstadoOrigen::Activo, EstadoOrigen::PendienteMapeo], true)) {
            return;
        }

        $nuevo = $this->previasPendientes($origen)->exists() ? EstadoOrigen::PendienteMapeo : EstadoOrigen::Activo;

        if ($nuevo !== $origen->estado) {
            $origen->forceFill(['estado' => $nuevo])->save();
        }
    }

    /**
     * Vistas previas del primer mapeo que esperan confirmación.
     *
     * @return Builder<SeccionImportacion>
     */
    public function previasPendientes(SeccionOrigen $origen): Builder
    {
        return SeccionImportacion::query()
            ->where('origen', SeccionImportacion::ORIGEN_NUBE)
            ->where('estado', EstadoImportacion::VistaPrevia->value)
            ->whereIn('seccion_archivo_id', SeccionArchivo::withTrashed()->where('seccion_origen_id', $origen->id)->select('id'));
    }

    /**
     * Hojas con datos del documento tal como están hoy en el disco; null si no
     * se pudo leer (corrupto, a medio subir, demasiado grande): no se toca nada.
     *
     * @return list<array{nombre: string, hoja: string, filas: list<list<mixed>>}>|null
     */
    public function leerHojas(SeccionOrigen $origen, SeccionArchivo $archivo): ?array
    {
        if (! $this->archivos->puedeLeerse($archivo->tamano) || ! is_file($this->archivos->rutaAbsoluta($archivo))) {
            return null;
        }

        try {
            $tablas = $this->extractor->tablas($this->archivos->rutaAbsoluta($archivo), $archivo->extension);
        } catch (ValidationException) {
            return null;
        }

        if ($tablas === []) {
            return null;
        }

        return array_map(fn (array $tabla): array => [
            'nombre' => $this->nombreTabla($origen, $archivo, $tabla['nombre']),
            'hoja' => $tabla['nombre'],
            'filas' => $tabla['filas'],
        ], $tablas);
    }

    /**
     * Aplica la hoja a su tabla espejo. `$forzar` salta las retenciones (lo usa
     * el Administrador al pulsar Aplicar o al editar el mapeo).
     *
     * @param  array{nombre: string, hoja: string, filas: list<list<mixed>>}|null  $hoja  null = la hoja ya no viene en el documento
     */
    public function aplicarMapeo(SeccionMapeo $mapeo, ?array $hoja, SeccionArchivo $archivo, ?User $usuario, bool $forzar = false): ?string
    {
        $tabla = $mapeo->tabla;
        $origen = $mapeo->origen;

        if ($tabla === null || $tabla->trashed() || $origen === null) {
            return __('La tabla espejo de «:hoja» ya no existe; desvincula y vuelve a vincular el origen para recrearla.', ['hoja' => $mapeo->hoja]);
        }

        $hash = $hoja === null ? null : $this->huella($hoja['filas']);

        if (! $forzar && $hash !== null && $hash === $mapeo->contenido_hash && ! $mapeo->estaRetenido()) {
            return null;
        }

        $vacia = $hoja === null || $this->sinDatos($hoja['filas'], $mapeo->fila_encabezado);

        if ($vacia && ! $forzar && $tabla->filas()->exists()) {
            $this->retener($mapeo, self::RETENIDO_VACIA, $tabla, $hash, 0);

            return __('La hoja «:hoja» llegó vacía: no se tocó la tabla. Confirma el cambio en Estado de sincronización.', ['hoja' => $mapeo->hoja]);
        }

        $hoja ??= $this->hojaSinFilas($mapeo);
        $hoja = $vacia ? $this->hojaSoloEncabezados($mapeo, $hoja) : $hoja;

        try {
            return $this->aplicarHoja($mapeo, $tabla, $hoja, $archivo, $usuario, $forzar, $hash);
        } catch (ValidationException $e) {
            return __('La hoja «:hoja» no se pudo aplicar: :motivo', ['hoja' => $mapeo->hoja, 'motivo' => collect($e->errors())->flatten()->first()]);
        }
    }

    /**
     * Huella estable del contenido de una hoja.
     *
     * @param  list<list<mixed>>  $filas
     */
    public function huella(array $filas): string
    {
        return hash('sha256', json_encode($filas, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
    }

    /**
     * Nombre de la tabla: la hoja si el origen es un archivo; «Archivo» · «Hoja» si es carpeta.
     */
    public function nombreTabla(SeccionOrigen $origen, SeccionArchivo $archivo, string $hoja): string
    {
        $nombreArchivo = pathinfo($archivo->nombre_original, PATHINFO_FILENAME);
        $hoja = $archivo->extension === 'csv' ? $nombreArchivo : $hoja;
        $nombre = $origen->tipo === SeccionOrigen::TIPO_CARPETA && $archivo->extension !== 'csv' ? $nombreArchivo.' · '.$hoja : $hoja;

        return Str::limit($nombre, self::MAX_NOMBRE, '');
    }

    private function esCandidato(SeccionOrigen $origen, SeccionArchivo $archivo): bool
    {
        return $origen->alimenta_tablas
            && $archivo->seccion_fila_id === null
            && in_array($archivo->extension, self::EXTENSIONES, true)
            && ! $origen->seccion->solo_documentos;
    }

    private function procesar(SeccionOrigen $origen, SeccionArchivo $archivo, ?User $usuario): ?string
    {
        if (! $this->esCandidato($origen, $archivo)) {
            return null;
        }

        $hojas = $this->leerHojas($origen, $archivo);
        $mapeos = SeccionMapeo::query()->where('seccion_archivo_id', $archivo->id)->with('tabla', 'origen')->get()->keyBy('hoja');

        // Ilegible: ni siquiera se retiene, la tabla queda como estaba hasta que llegue un archivo bueno.
        if ($hojas === null) {
            return __('El documento «:doc» no se pudo leer (¿corrupto o a medio subir?): las tablas no se tocaron.', ['doc' => $archivo->nombre_original]);
        }

        // Mientras el documento tenga su mapeo por confirmar, o el origen no tenga ninguno, solo se prepara la vista previa.
        if ($mapeos->isEmpty() && ($this->previasPendientes($origen)->where('seccion_archivo_id', $archivo->id)->exists() || ! $origen->mapeos()->exists())) {
            return $this->prepararPrimerMapeo($origen, $archivo, $hojas);
        }

        $avisos = [];

        foreach ($hojas as $hoja) {
            $mapeo = $mapeos->get($hoja['hoja']);
            $aviso = $mapeo === null
                ? $this->crearTablaAutomatica($origen, $archivo, $hoja, $usuario)
                : $this->aplicarMapeo($mapeo, $hoja, $archivo, $usuario);

            if ($aviso !== null) {
                $avisos[] = $aviso;
            }
        }

        $presentes = array_column($hojas, 'hoja');

        foreach ($mapeos as $nombreHoja => $mapeo) {
            if (! in_array($nombreHoja, $presentes, true) && ($aviso = $this->aplicarMapeo($mapeo, null, $archivo, $usuario)) !== null) {
                $avisos[] = $aviso;
            }
        }

        return $avisos === [] ? null : implode(' ', $avisos);
    }

    /**
     * @param  list<array{nombre: string, hoja: string, filas: list<list<mixed>>}>  $hojas
     */
    private function prepararPrimerMapeo(SeccionOrigen $origen, SeccionArchivo $archivo, array $hojas): ?string
    {
        $conDatos = array_values(array_filter($hojas, fn (array $hoja): bool => ! $this->sinDatos($hoja['filas'], $this->preparador->filaEncabezadoSugerida($hoja['filas']))));

        if ($conDatos === []) {
            return null;
        }

        $previa = $this->previasPendientes($origen)->where('seccion_archivo_id', $archivo->id)->first();

        if ($previa !== null) {
            // El documento cambió antes de confirmar: la vista previa muestra lo último.
            $previa->forceFill(['tablas' => $conDatos])->save();

            return null;
        }

        $importacion = $this->importaciones->desdeTablas($origen->seccion, $archivo, $conDatos, SeccionImportacion::ORIGEN_NUBE, null);
        $importacion->forceFill(['resumen' => ['espejo' => true, 'seccion_origen_id' => $origen->id]])->save();

        $this->refrescarEstado($origen->refresh());

        return __('Hay un Excel nuevo («:doc») cuyo mapeo de columnas falta confirmar. Las tablas se crearán al confirmarlo.', ['doc' => $archivo->nombre_original]);
    }

    /**
     * Hoja nueva (o Excel nuevo) en un origen ya mapeado: tabla con valores sugeridos.
     *
     * @param  array{nombre: string, hoja: string, filas: list<list<mixed>>}  $hoja
     */
    private function crearTablaAutomatica(SeccionOrigen $origen, SeccionArchivo $archivo, array $hoja, ?User $usuario): ?string
    {
        $fila = $this->preparador->filaEncabezadoSugerida($hoja['filas']);

        if ($this->sinDatos($hoja['filas'], $fila)) {
            return null;
        }

        return DB::transaction(function () use ($origen, $archivo, $hoja, $fila, $usuario): string {
            $tabla = $this->libros->crearTabla($origen->seccion, $this->nombreLibre($origen, $hoja['nombre']));
            $tabla->forceFill(['seccion_origen_id' => $origen->id])->save();

            $mapeo = new SeccionMapeo;
            $mapeo->forceFill([
                'seccion_origen_id' => $origen->id,
                'seccion_archivo_id' => $archivo->id,
                'hoja' => $hoja['hoja'],
                'seccion_tabla_id' => $tabla->id,
                'fila_encabezado' => $fila,
                'columnas' => [],
            ])->save();
            $mapeo->setRelation('tabla', $tabla)->setRelation('origen', $origen);

            $this->aplicarHoja($mapeo, $tabla, $hoja, $archivo, $usuario, true, $this->huella($hoja['filas']), automatica: true);

            return __('Tabla nueva creada automáticamente: revisar mapeo («:tabla»).', ['tabla' => $tabla->nombre]);
        });
    }

    /**
     * @param  array{nombre: string, hoja: string, filas: list<list<mixed>>}  $hoja
     */
    private function aplicarHoja(SeccionMapeo $mapeo, SeccionTabla $tabla, array $hoja, SeccionArchivo $archivo, ?User $usuario, bool $forzar, ?string $hash, bool $automatica = false): ?string
    {
        $importacion = new SeccionImportacion(['tablas' => [$hoja]]);
        $importacion->forceFill(['seccion_id' => $tabla->seccion_id]);
        $propuesta = $this->importaciones->propuesta($importacion, 0, null, $tabla, $automatica ? null : $mapeo);

        if (! $automatica && $mapeo->llave_clave !== null && $propuesta['llave'] === null && ! $forzar) {
            $this->retener($mapeo, self::RETENIDO_LLAVE, $tabla, $hash, 0);

            return __('La columna llave de «:hoja» ya no viene en el Excel: no se tocó la tabla. Revisa el mapeo.', ['hoja' => $mapeo->hoja]);
        }

        $opciones = new OpcionesImportacion(
            tabla: 0,
            filaEncabezado: $propuesta['fila_encabezado'],
            mapeo: array_map('strval', $propuesta['mapeo']),
            tipos: array_map(fn ($tipo) => TipoColumna::from($tipo), $propuesta['tipos']),
            modo: ModoImportacion::Espejo,
            llave: $propuesta['llave'],
        );

        $plan = $this->planificador->planear($tabla, [$hoja], $opciones, $archivo->id);

        if (! $forzar && ($masivo = $this->cambioMasivo($tabla, $plan, $propuesta['llave'] !== null, $archivo->id)) !== null) {
            $this->retener($mapeo, self::RETENIDO_MASIVO, $tabla, $hash, $masivo);

            return __('Cambio masivo retenido en «:hoja»: se archivarían :n filas. Confirma o ignora en Estado de sincronización.', ['hoja' => $mapeo->hoja, 'n' => $masivo]);
        }

        $columnas = $this->columnasDelMapeo($mapeo, $propuesta, $plan);
        $llave = $this->claveDeLlave($propuesta, $plan);

        DB::transaction(function () use ($mapeo, $tabla, $hoja, $archivo, $usuario, $opciones, $plan, $hash, $columnas, $llave, $automatica): void {
            if ($this->hayCambios($plan)) {
                $importacion = $this->importaciones->desdeTablas($tabla->seccion, $archivo, [$hoja], SeccionImportacion::ORIGEN_NUBE, null, $tabla);
                $this->importaciones->aplicar($importacion, $opciones, $usuario);
            }

            $mapeo->forceFill([
                'columnas' => $columnas,
                'llave_clave' => $automatica ? $llave : $mapeo->llave_clave,
                'contenido_hash' => $hash,
                'retenido' => null,
            ])->save();
        });

        return null;
    }

    /**
     * Filas que desaparecerían si son más del tope configurado; null si no es masivo.
     *
     * @param  array<string, mixed>  $plan
     */
    private function cambioMasivo(SeccionTabla $tabla, array $plan, bool $conLlave, int $archivoId): ?int
    {
        $actuales = $this->planificador->filasDeLaTabla($tabla, $archivoId)->count();

        if ($actuales === 0) {
            return null;
        }

        $desaparecen = $conLlave ? count($plan['eliminadas']) : max(0, $actuales - count($plan['nuevas']));
        $tope = (float) config('secciones.nube.espejo_max_archivado_pct', 50);

        return $desaparecen * 100 > $tope * $actuales ? $desaparecen : null;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function hayCambios(array $plan): bool
    {
        return $plan['nuevas'] !== [] || $plan['actualizadas'] !== [] || $plan['eliminadas'] !== [] || $plan['columnas_nuevas'] !== [];
    }

    /**
     * Columnas del mapeo tras esta aplicación: las que ya tenía más las columnas
     * nuevas del Excel (con la clave que el plan les asignó) y las ignoradas con datos.
     *
     * @param  array<string, mixed>  $propuesta
     * @param  array<string, mixed>  $plan
     * @return list<array{encabezado_normalizado: string, clave: string}>
     */
    private function columnasDelMapeo(SeccionMapeo $mapeo, array $propuesta, array $plan): array
    {
        $columnas = $mapeo->columnas ?? [];

        foreach ($propuesta['encabezados'] as $indice => $encabezado) {
            $normalizado = Texto::normalizar($encabezado);
            $posicion = array_search($normalizado, array_column($columnas, 'encabezado_normalizado'), true);
            $claveNueva = $plan['columnas_nuevas'][$indice]['clave'] ?? null;
            $conDatos = array_filter(array_column($propuesta['muestra'], $indice), fn ($valor) => $valor !== null && $valor !== '') !== [];
            $ignorada = ($propuesta['mapeo'][$indice] ?? null) === OpcionesImportacion::DESTINO_IGNORAR && $conDatos;
            $entrada = $claveNueva !== null ? $claveNueva : ($ignorada && $posicion === false ? SeccionMapeo::IGNORAR : null);

            if ($entrada === null) {
                continue;
            }

            $nueva = ['encabezado_normalizado' => $normalizado, 'clave' => $entrada];
            $posicion === false ? $columnas[] = $nueva : $columnas[$posicion] = $nueva;
        }

        return $columnas;
    }

    /**
     * Clave de columna de la llave sugerida (solo se usa al crear una tabla automática).
     *
     * @param  array<string, mixed>  $propuesta
     * @param  array<string, mixed>  $plan
     */
    private function claveDeLlave(array $propuesta, array $plan): ?string
    {
        $indice = $propuesta['llave'];

        if ($indice === null) {
            return null;
        }

        $destino = $propuesta['mapeo'][$indice] ?? null;

        return $destino === OpcionesImportacion::DESTINO_NUEVA ? ($plan['columnas_nuevas'][$indice]['clave'] ?? null) : $destino;
    }

    /**
     * @param  list<list<mixed>>  $filas
     */
    private function sinDatos(array $filas, int $filaEncabezado): bool
    {
        foreach ($this->preparador->datos($filas, $filaEncabezado) as $fila) {
            if (array_filter($fila, fn ($valor) => $valor !== null && $valor !== '') !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * Hoja con solo la fila de encabezados guardados en el mapeo (la hoja desapareció o quedó vacía).
     *
     * @return array{nombre: string, hoja: string, filas: list<list<mixed>>}
     */
    private function hojaSinFilas(SeccionMapeo $mapeo): array
    {
        $encabezados = collect($mapeo->columnas ?? [])->pluck('encabezado_normalizado')->all();

        return [
            'nombre' => $mapeo->tabla?->nombre ?? $mapeo->hoja,
            'hoja' => $mapeo->hoja,
            'filas' => [$encabezados],
        ];
    }

    /**
     * Una hoja vacía confirmada por el usuario se aplica como "sin filas".
     *
     * @param  array{nombre: string, hoja: string, filas: list<list<mixed>>}  $hoja
     * @return array{nombre: string, hoja: string, filas: list<list<mixed>>}
     */
    private function hojaSoloEncabezados(SeccionMapeo $mapeo, array $hoja): array
    {
        $fila = $hoja['filas'][min($mapeo->fila_encabezado, max(0, count($hoja['filas']) - 1))] ?? [];

        return ['filas' => [$fila]] + $hoja;
    }

    private function nombreLibre(SeccionOrigen $origen, string $nombre): string
    {
        $candidato = $nombre;
        $contador = 2;

        while ($origen->seccion->tablas()->where('nombre', $candidato)->exists()) {
            $candidato = Str::limit($nombre, self::MAX_NOMBRE - 6, '').' ('.$contador++.')';
        }

        return $candidato;
    }

    private function retener(SeccionMapeo $mapeo, string $motivo, SeccionTabla $tabla, ?string $hash, int $filasAArchivar): void
    {
        $yaRetenido = $mapeo->retenido !== null && ($mapeo->retenido['hash'] ?? null) === $hash && ($mapeo->retenido['motivo'] ?? null) === $motivo;

        $mapeo->forceFill(['retenido' => [
            'motivo' => $motivo,
            'archivar' => $filasAArchivar,
            'total' => SeccionFila::query()->where('seccion_tabla_id', $tabla->id)->count(),
            'hash' => $hash,
            'detectado_en' => now()->toIso8601String(),
        ]])->save();

        if ($yaRetenido) {
            return;
        }

        activity('seccion')
            ->performedOn($tabla->seccion)
            ->event('cambio_masivo_retenido')
            ->withProperties(['origen' => $mapeo->origen?->nombre, 'hoja' => $mapeo->hoja, 'tabla' => $tabla->nombre, 'motivo' => $motivo, 'filas_a_archivar' => $filasAArchivar])
            ->log('Cambio masivo retenido: no se aplicó a la tabla espejo hasta que el Administrador lo confirme.');
    }
}
