<?php

namespace App\Services\Secciones;

use App\Enums\EstadoImportacion;
use App\Enums\ModoImportacion;
use App\Enums\TipoColumna;
use App\Models\Seccion;
use App\Models\SeccionArchivo;
use App\Models\SeccionColumna;
use App\Models\SeccionFila;
use App\Models\SeccionImportacion;
use App\Models\User;
use App\Support\Texto;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ciclo de vida de un documento con tabla(s) arrastrado a una sección:
 * detectar -> vista previa -> aplicar -> (deshacer).
 *
 * Las filas se escriben en bloque (sin una entrada de bitácora por fila);
 * la bitácora recibe un evento resumen por importación aplicada/deshecha,
 * con el conteo de cambios y quién la hizo.
 */
final class ImportacionService
{
    private const CHUNK = 500;

    private const MUESTRA = 8;

    /**
     * Nombres de columna que suelen identificar un registro (llave sugerida).
     */
    private const PISTAS_LLAVE = ['no', 'num', 'numero', 'clave', 'id', 'folio', 'expediente', 'curp', 'rfc', 'matricula', 'serie', 'codigo', 'equipo'];

    public function __construct(
        private readonly ExtractorDocumentos $extractor,
        private readonly ArchivoService $archivos,
        private readonly PreparadorTabla $preparador,
        private readonly NormalizadorValores $normalizador,
        private readonly PlanificadorImportacion $planificador,
    ) {}

    /**
     * Importación en vista previa si el documento trae al menos una tabla
     * legible sin IA; null si no (queda solo como documento).
     */
    public function desdeArchivo(Seccion $seccion, SeccionArchivo $archivo, User $usuario): ?SeccionImportacion
    {
        if (! $this->archivos->puedeLeerse($archivo->tamano)) {
            return null;
        }

        $tablas = $this->extractor->tablas($this->archivos->rutaAbsoluta($archivo), $archivo->extension);

        return $tablas === [] ? null : $this->desdeTablas($seccion, $archivo, $tablas, SeccionImportacion::ORIGEN_ARCHIVO, $usuario);
    }

    /**
     * @param  list<array{nombre: string, filas: list<list<mixed>>}>  $tablas
     */
    public function desdeTablas(Seccion $seccion, ?SeccionArchivo $archivo, array $tablas, string $origen, User $usuario): SeccionImportacion
    {
        $importacion = new SeccionImportacion([
            'seccion_archivo_id' => $archivo?->id,
            'origen' => $origen,
            'estado' => EstadoImportacion::VistaPrevia,
            'tablas' => $tablas,
        ]);

        $importacion->forceFill(['seccion_id' => $seccion->id, 'usuario_id' => $usuario->id])->save();

        return $importacion;
    }

    /**
     * Lo que la vista previa necesita para una tabla del documento:
     * encabezados, muestra de filas y mapeo/tipos/llave/modo sugeridos.
     *
     * @return array<string, mixed>
     */
    public function propuesta(SeccionImportacion $importacion, int $tabla, ?int $filaEncabezado = null): array
    {
        $filas = $importacion->tablas[$tabla]['filas'] ?? $importacion->tablas[0]['filas'] ?? [];
        $tabla = isset($importacion->tablas[$tabla]) ? $tabla : 0;

        $filaEncabezado ??= $this->preparador->filaEncabezadoSugerida($filas);
        $filaEncabezado = max(0, min($filaEncabezado, count($filas) - 1));

        $encabezados = $this->preparador->encabezados($filas, $filaEncabezado);
        $datos = $this->preparador->datos($filas, $filaEncabezado);
        $columnas = $importacion->seccion->columnas()->get();

        $mapeo = [];
        $tipos = [];

        foreach ($encabezados as $indice => $nombre) {
            $valores = array_column($datos, $indice);
            $existente = $columnas->first(fn (SeccionColumna $columna) => Texto::normalizar($columna->nombre) === Texto::normalizar($nombre) || $columna->clave === Texto::normalizar($nombre));
            $sinDatos = array_filter($valores, fn ($valor) => $valor !== null && $valor !== '') === [];

            $mapeo[$indice] = match (true) {
                $existente !== null => $existente->clave,
                $sinDatos => OpcionesImportacion::DESTINO_IGNORAR,
                default => OpcionesImportacion::DESTINO_NUEVA,
            };
            $tipos[$indice] = $this->normalizador->inferirTipo($valores)->value;
        }

        $llave = $this->llaveSugerida($encabezados, $datos);
        $tieneFilas = $importacion->seccion->filas()->exists();

        return [
            'tabla' => $tabla,
            'fila_encabezado' => $filaEncabezado,
            'encabezados' => $encabezados,
            'muestra' => array_slice($datos, 0, 10),
            'total_filas' => count($datos),
            'primeras_filas' => array_slice($filas, 0, 15),
            'mapeo' => $mapeo,
            'tipos' => $tipos,
            'llave' => $llave,
            'modo' => match (true) {
                ! $tieneFilas => ModoImportacion::Agregar->value,
                $llave !== null => ModoImportacion::Actualizar->value,
                default => ModoImportacion::Agregar->value,
            },
        ];
    }

    /**
     * Resumen de cambios para la vista previa (no escribe nada).
     *
     * @return array<string, mixed>
     */
    public function calcular(SeccionImportacion $importacion, OpcionesImportacion $opciones): array
    {
        $this->exigirVistaPrevia($importacion);
        $plan = $this->planificador->planear($importacion->seccion, $importacion->tablas, $opciones);

        return [
            'nuevas' => count($plan['nuevas']),
            'actualizadas' => count($plan['actualizadas']),
            'sin_cambios' => $plan['sin_cambios'],
            'eliminadas' => count($plan['eliminadas']),
            'no_vienen' => $plan['no_vienen'],
            'columnas_nuevas' => array_values(array_map(fn (array $columna) => $columna['nombre'], $plan['columnas_nuevas'])),
            'muestra_nuevas' => array_slice($plan['nuevas'], 0, self::MUESTRA),
            'muestra_actualizadas' => array_slice(array_map(
                fn (array $cambio) => $this->diferencias($cambio['antes'], $cambio['despues']),
                $plan['actualizadas'],
            ), 0, self::MUESTRA, true),
        ];
    }

    public function aplicar(SeccionImportacion $importacion, OpcionesImportacion $opciones, User $usuario): SeccionImportacion
    {
        $this->exigirVistaPrevia($importacion);
        $seccion = $importacion->seccion;

        return DB::transaction(function () use ($importacion, $opciones, $usuario, $seccion): SeccionImportacion {
            // Bloqueo de fila: un doble clic en "Aplicar" no puede aplicar dos veces.
            $importacion = SeccionImportacion::query()->lockForUpdate()->findOrFail($importacion->id);
            $this->exigirVistaPrevia($importacion);
            $ahora = now();

            $plan = $this->planificador->planear($seccion, $importacion->tablas, $opciones);

            $columnasCreadas = $this->crearColumnas($seccion, $plan['columnas_nuevas']);
            $this->insertarFilas($seccion, $importacion, $plan['nuevas'], $usuario, $ahora);
            $this->actualizarFilas($plan['actualizadas'], $usuario, $ahora);
            $this->eliminarFilas($plan['eliminadas'], $ahora);

            $resumen = [
                'nuevas' => count($plan['nuevas']),
                'actualizadas' => count($plan['actualizadas']),
                'sin_cambios' => $plan['sin_cambios'],
                'eliminadas' => count($plan['eliminadas']),
                'columnas_nuevas' => count($columnasCreadas),
                'tabla' => $importacion->tablas[$opciones->tabla]['nombre'] ?? null,
            ];

            $importacion->update([
                'estado' => EstadoImportacion::Aplicada,
                'modo' => $opciones->modo,
                'resumen' => $resumen,
                'cambios' => [
                    'actualizadas' => array_map(fn (array $cambio) => $cambio['antes'], $plan['actualizadas']),
                    'eliminadas' => $plan['eliminadas'],
                    'columnas_creadas' => $columnasCreadas,
                ],
                // El contenido completo del documento ya está en la tabla y
                // sigue en el archivo original: no se conserva duplicado.
                'tablas' => [],
                'aplicada_at' => $ahora,
            ]);

            activity('seccion')
                ->causedBy($usuario)
                ->performedOn($seccion)
                ->event('importacion_aplicada')
                ->withProperties(['importacion_id' => $importacion->id, 'modo' => $opciones->modo->value, 'resumen' => $resumen])
                ->log('Importación de documento aplicada a la sección.');

            return $importacion;
        });
    }

    /**
     * Revierte una carga: las filas que creó se dan de baja (baja lógica,
     * recuperable), las que actualizó vuelven a su valor anterior y las que
     * eliminó se restauran. Ver motivoParaNoDeshacer() para cuándo se impide.
     */
    public function deshacer(SeccionImportacion $importacion, User $usuario): SeccionImportacion
    {
        return DB::transaction(function () use ($importacion, $usuario): SeccionImportacion {
            $importacion = SeccionImportacion::query()->lockForUpdate()->findOrFail($importacion->id);
            $motivo = $this->motivoParaNoDeshacer($importacion);

            if ($motivo !== null) {
                throw ValidationException::withMessages(['importacion' => $motivo]);
            }

            $cambios = $importacion->cambios ?? [];
            $ahora = now();

            SeccionFila::query()->where('seccion_importacion_id', $importacion->id)->toBase()->update(['deleted_at' => $ahora]);

            foreach ($cambios['actualizadas'] ?? [] as $id => $antes) {
                SeccionFila::withTrashed()->whereKey($id)->toBase()->update([
                    'datos' => self::json($antes),
                    'texto_busqueda' => SeccionFila::textoBusqueda($antes),
                    'updated_at' => $ahora,
                ]);
            }

            foreach (array_chunk($cambios['eliminadas'] ?? [], self::CHUNK) as $ids) {
                SeccionFila::withTrashed()->whereIn('id', $ids)->toBase()->update(['deleted_at' => null]);
            }

            $this->quitarColumnasSinValores($importacion, $cambios['columnas_creadas'] ?? []);

            $importacion->update(['estado' => EstadoImportacion::Deshecha, 'deshecha_at' => now()]);

            activity('seccion')
                ->causedBy($usuario)
                ->performedOn($importacion->seccion)
                ->event('importacion_deshecha')
                ->withProperties(['importacion_id' => $importacion->id, 'resumen' => $importacion->resumen])
                ->log('Importación de documento deshecha.');

            return $importacion;
        });
    }

    public function descartar(SeccionImportacion $importacion): void
    {
        $this->exigirVistaPrevia($importacion);
        $importacion->delete();
    }

    public function puedeDeshacerse(SeccionImportacion $importacion): bool
    {
        return $this->motivoParaNoDeshacer($importacion) === null;
    }

    /**
     * La carga aplicada más reciente de la sección (la única candidata a
     * deshacerse), o null.
     */
    public function ultimaAplicada(Seccion $seccion): ?SeccionImportacion
    {
        return $seccion->importaciones()
            ->where('estado', EstadoImportacion::Aplicada->value)
            ->orderByDesc('aplicada_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Por qué no se puede deshacer una carga, o null si sí se puede. Se
     * impide si no es la carga aplicada más reciente o si, después de
     * aplicarla, alguien editó, borró o adjuntó documentos a las filas que
     * tocó: deshacer pisaría ese trabajo manual.
     */
    public function motivoParaNoDeshacer(SeccionImportacion $importacion): ?string
    {
        if ($importacion->estado !== EstadoImportacion::Aplicada) {
            return __('Esta carga no está aplicada.');
        }

        if (! $this->ultimaAplicada($importacion->seccion)?->is($importacion)) {
            return __('Solo se puede deshacer la carga aplicada más reciente de esta sección.');
        }

        $aplicada = $importacion->aplicada_at;
        $actualizadas = array_map('intval', array_keys($importacion->cambios['actualizadas'] ?? []));

        $tocadasDespues = SeccionFila::withTrashed()
            ->where(fn ($q) => $q->where('seccion_importacion_id', $importacion->id)->orWhereIn('id', $actualizadas))
            ->where(fn ($q) => $q->where('updated_at', '>', $aplicada)->orWhere('deleted_at', '>', $aplicada))
            ->count();

        if ($tocadasDespues > 0) {
            return trans_choice(
                '{1} No se puede deshacer: un registro de esta carga se modificó después de aplicarla.|[2,*] No se puede deshacer: :count registros de esta carga se modificaron después de aplicarla.',
                $tocadasDespues,
            );
        }

        $conAdjuntos = SeccionArchivo::query()
            ->whereIn('seccion_fila_id', SeccionFila::withTrashed()->where('seccion_importacion_id', $importacion->id)->select('id'))
            ->exists();

        return $conAdjuntos ? __('No se puede deshacer: hay documentos adjuntos a registros de esta carga.') : null;
    }

    /**
     * @param  array<int, array{nombre: string, clave: string, tipo: TipoColumna}>  $columnasNuevas
     * @return list<int>
     */
    private function crearColumnas(Seccion $seccion, array $columnasNuevas): array
    {
        $orden = (int) $seccion->columnas()->max('orden');
        $ids = [];

        foreach ($columnasNuevas as $columna) {
            $ids[] = $seccion->columnas()->create([
                'nombre' => $columna['nombre'],
                'clave' => $columna['clave'],
                'tipo' => $columna['tipo'],
                'orden' => ++$orden,
            ])->id;
        }

        return $ids;
    }

    /**
     * @param  list<array<string, mixed>>  $nuevas
     */
    private function insertarFilas(Seccion $seccion, SeccionImportacion $importacion, array $nuevas, User $usuario, CarbonInterface $ahora): void
    {
        foreach (array_chunk($nuevas, self::CHUNK) as $bloque) {
            SeccionFila::query()->insert(array_map(fn (array $datos) => [
                'seccion_id' => $seccion->id,
                'datos' => self::json($datos),
                'texto_busqueda' => SeccionFila::textoBusqueda($datos),
                'seccion_importacion_id' => $importacion->id,
                'creado_por' => $usuario->id,
                'actualizado_por' => $usuario->id,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ], $bloque));
        }
    }

    /**
     * @param  array<int, array{antes: array<string, mixed>, despues: array<string, mixed>}>  $actualizadas
     */
    private function actualizarFilas(array $actualizadas, User $usuario, CarbonInterface $ahora): void
    {
        foreach ($actualizadas as $id => $cambio) {
            SeccionFila::query()->whereKey($id)->toBase()->update([
                'datos' => self::json($cambio['despues']),
                'texto_busqueda' => SeccionFila::textoBusqueda($cambio['despues']),
                'actualizado_por' => $usuario->id,
                'updated_at' => $ahora,
            ]);
        }
    }

    /**
     * JSON para escrituras directas: sustituye UTF-8 inválido (texto que
     * viene de PDF/Word) en lugar de fallar en silencio.
     *
     * @param  array<string, mixed>  $datos
     */
    public static function json(array $datos): string
    {
        return json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  list<int>  $ids
     */
    private function eliminarFilas(array $ids, CarbonInterface $ahora): void
    {
        foreach (array_chunk($ids, self::CHUNK) as $bloque) {
            SeccionFila::query()->whereIn('id', $bloque)->toBase()->update(['deleted_at' => $ahora]);
        }
    }

    /**
     * Al deshacer, las columnas que creó la importación se quitan salvo que
     * alguien ya haya capturado valores en ellas después.
     *
     * @param  list<int>  $ids
     */
    private function quitarColumnasSinValores(SeccionImportacion $importacion, array $ids): void
    {
        $columnas = SeccionColumna::query()->whereIn('id', $ids)->where('seccion_id', $importacion->seccion_id)->get()->keyBy('clave');

        if ($columnas->isEmpty()) {
            return;
        }

        // Una sola pasada por las filas, anotando qué claves tienen valor.
        $conValor = [];

        SeccionFila::query()->where('seccion_id', $importacion->seccion_id)->select(['id', 'datos'])
            ->chunkById(self::CHUNK, function ($filas) use ($columnas, &$conValor): void {
                foreach ($filas as $fila) {
                    foreach ($columnas->keys() as $clave) {
                        if (($fila->datos[$clave] ?? null) !== null) {
                            $conValor[$clave] = true;
                        }
                    }
                }
            });

        $columnas->reject(fn (SeccionColumna $columna) => isset($conValor[$columna->clave]))->each->delete();
    }

    /**
     * @param  list<string>  $encabezados
     * @param  list<list<mixed>>  $datos
     */
    private function llaveSugerida(array $encabezados, array $datos): ?int
    {
        if ($datos === []) {
            return null;
        }

        foreach ($encabezados as $indice => $nombre) {
            $palabras = preg_split('/[^a-z0-9]+/', Texto::normalizar($nombre)) ?: [];

            if (array_intersect($palabras, self::PISTAS_LLAVE) === []) {
                continue;
            }

            $valores = array_map(fn (array $fila) => Texto::normalizar($fila[$indice] ?? null), $datos);

            if (! in_array('', $valores, true) && count(array_unique($valores)) === count($valores)) {
                return $indice;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $antes
     * @param  array<string, mixed>  $despues
     * @return array<string, array{antes: mixed, despues: mixed}>
     */
    private function diferencias(array $antes, array $despues): array
    {
        $cambios = [];

        foreach ($despues as $clave => $valor) {
            if (! PlanificadorImportacion::mismoValor($antes[$clave] ?? null, $valor)) {
                $cambios[$clave] = ['antes' => $antes[$clave] ?? null, 'despues' => $valor];
            }
        }

        return $cambios;
    }

    private function exigirVistaPrevia(SeccionImportacion $importacion): void
    {
        if (! $importacion->isVistaPrevia()) {
            throw ValidationException::withMessages(['importacion' => __('Esta carga ya fue aplicada o descartada.')]);
        }
    }
}
