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
            $plan = $this->planificador->planear($seccion, $importacion->tablas, $opciones);

            $columnasCreadas = $this->crearColumnas($seccion, $plan['columnas_nuevas']);
            $this->insertarFilas($seccion, $importacion, $plan['nuevas'], $usuario);
            $this->actualizarFilas($plan['actualizadas'], $usuario);
            $this->eliminarFilas($plan['eliminadas']);

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
                'aplicada_at' => now(),
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
     * Solo se puede deshacer la última importación aplicada de la sección:
     * deshacer una anterior podría pisar cambios de las posteriores.
     */
    public function deshacer(SeccionImportacion $importacion, User $usuario): SeccionImportacion
    {
        if (! $this->puedeDeshacerse($importacion)) {
            throw ValidationException::withMessages(['importacion' => __('Solo se puede deshacer la carga aplicada más reciente de esta sección.')]);
        }

        return DB::transaction(function () use ($importacion, $usuario): SeccionImportacion {
            $cambios = $importacion->cambios ?? [];

            SeccionFila::withTrashed()->where('seccion_importacion_id', $importacion->id)->forceDelete();

            foreach ($cambios['actualizadas'] ?? [] as $id => $antes) {
                SeccionFila::withTrashed()->whereKey($id)->toBase()->update([
                    'datos' => json_encode($antes, JSON_UNESCAPED_UNICODE),
                    'texto_busqueda' => SeccionFila::textoBusqueda($antes),
                    'updated_at' => now(),
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
        if ($importacion->estado !== EstadoImportacion::Aplicada) {
            return false;
        }

        return ! SeccionImportacion::query()
            ->where('seccion_id', $importacion->seccion_id)
            ->where('estado', EstadoImportacion::Aplicada->value)
            ->where('aplicada_at', '>', $importacion->aplicada_at)
            ->exists()
            && ! SeccionImportacion::query()
                ->where('seccion_id', $importacion->seccion_id)
                ->where('estado', EstadoImportacion::Aplicada->value)
                ->where('aplicada_at', $importacion->aplicada_at)
                ->where('id', '>', $importacion->id)
                ->exists();
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
    private function insertarFilas(Seccion $seccion, SeccionImportacion $importacion, array $nuevas, User $usuario): void
    {
        $ahora = now();

        foreach (array_chunk($nuevas, self::CHUNK) as $bloque) {
            SeccionFila::query()->insert(array_map(fn (array $datos) => [
                'seccion_id' => $seccion->id,
                'datos' => json_encode($datos, JSON_UNESCAPED_UNICODE),
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
    private function actualizarFilas(array $actualizadas, User $usuario): void
    {
        foreach ($actualizadas as $id => $cambio) {
            SeccionFila::query()->whereKey($id)->toBase()->update([
                'datos' => json_encode($cambio['despues'], JSON_UNESCAPED_UNICODE),
                'texto_busqueda' => SeccionFila::textoBusqueda($cambio['despues']),
                'actualizado_por' => $usuario->id,
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * @param  list<int>  $ids
     */
    private function eliminarFilas(array $ids): void
    {
        foreach (array_chunk($ids, self::CHUNK) as $bloque) {
            SeccionFila::query()->whereIn('id', $bloque)->toBase()->update(['deleted_at' => now()]);
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
        SeccionColumna::query()->whereIn('id', $ids)->where('seccion_id', $importacion->seccion_id)->get()
            ->each(function (SeccionColumna $columna): void {
                $conValor = SeccionFila::query()
                    ->where('seccion_id', $columna->seccion_id)
                    ->get(['datos'])
                    ->contains(fn (SeccionFila $fila) => ($fila->datos[$columna->clave] ?? null) !== null);

                if (! $conValor) {
                    $columna->delete();
                }
            });
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
            if (($antes[$clave] ?? null) != $valor) {
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
