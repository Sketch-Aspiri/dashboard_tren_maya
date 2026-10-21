<?php

namespace App\Services\Secciones;

use App\Enums\EstadoImportacion;
use App\Enums\ModoImportacion;
use App\Enums\TipoColumna;
use App\Models\Seccion;
use App\Models\SeccionArchivo;
use App\Models\SeccionImportacion;
use App\Models\SeccionTabla;
use App\Models\User;
use App\Support\Texto;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Carga de un libro con varias hojas en una sola operación: cada hoja con
 * datos se vuelve una tabla propia DENTRO de la misma sección, con el
 * nombre de la hoja (si la sección ya tiene una tabla con ese nombre se
 * carga en ella). No se crean secciones ni entradas de menú.
 *
 * Toda la carga es una sola importación: una transacción, un evento
 * resumen en la bitácora y un solo "deshacer" para todas las hojas
 * (ImportacionService::deshacer lee el registro de cambios que se guarda
 * aquí, incluidas las tablas creadas).
 */
final class LibroImportacionService
{
    private const MAX_NOMBRE = 160;

    public function __construct(
        private readonly ImportacionService $importaciones,
        private readonly PlanificadorImportacion $planificador,
        private readonly PreparadorTabla $preparador,
        private readonly ExtractorDocumentos $extractor,
        private readonly ArchivoService $archivos,
    ) {}

    /**
     * Cuántas hojas del documento tienen datos (más de una activa la vista
     * previa de libro).
     */
    public function esLibro(SeccionImportacion $importacion): bool
    {
        return count($importacion->tablas) >= 2;
    }

    /**
     * La carga viene de un archivo Excel guardado (no de Word ni de la IA).
     */
    public function esExcel(SeccionImportacion $importacion): bool
    {
        return $importacion->origen === SeccionImportacion::ORIGEN_ARCHIVO
            && in_array($importacion->archivo?->extension, ['xlsx', 'xls'], true);
    }

    /**
     * Una propuesta (encabezados, mapeo, tipos, llave) por hoja, calculada
     * contra la tabla destino que tendría cada una.
     *
     * @return list<array<string, mixed>>
     */
    public function propuestas(SeccionImportacion $importacion): array
    {
        $propuestas = [];

        foreach ($importacion->tablas as $indice => $tabla) {
            $destino = $this->destinoExistente($importacion->seccion, $tabla['nombre']);
            $propuesta = $this->importaciones->propuesta($importacion, $indice, null, $destino ?? new SeccionTabla);

            $propuestas[] = $propuesta + [
                'nombre' => $tabla['nombre'],
                'destino_nuevo' => $destino === null,
                'columnas_destino' => $destino?->columnas()->get(['clave', 'nombre'])->all() ?? [],
                'vacia' => $propuesta['total_filas'] === 0 || $this->sinDatos($tabla['filas'], $propuesta['fila_encabezado']),
            ];
        }

        return $propuestas;
    }

    /**
     * Hojas con datos de un Excel ya guardado en el repositorio que todavía
     * no se cargaron como tabla (una carga aplicada de ese archivo ya cubre
     * las hojas que menciona en su resumen). Base de la carga retroactiva.
     *
     * @return list<array{nombre: string, filas: list<list<mixed>>}>
     */
    public function hojasPendientes(SeccionArchivo $archivo): array
    {
        if (! $this->archivos->puedeLeerse($archivo->tamano)) {
            return [];
        }

        $ruta = $this->archivos->rutaAbsoluta($archivo);

        if (! is_file($ruta)) {
            return [];
        }

        $cargadas = $this->hojasYaCargadas($archivo);
        $pendientes = [];

        foreach ($this->extractor->tablas($ruta, $archivo->extension) as $tabla) {
            if (! in_array($tabla['nombre'], $cargadas, true) && ! $this->sinDatos($tabla['filas'], $this->preparador->filaEncabezadoSugerida($tabla['filas']))) {
                $pendientes[] = $tabla;
            }
        }

        return $pendientes;
    }

    /**
     * Carga automática (mapeo y tipos sugeridos, modo agregar) de las hojas
     * pendientes de un archivo existente, en una sola transacción y con un
     * solo "deshacer".
     *
     * @param  list<array{nombre: string, filas: list<list<mixed>>}>  $tablas
     */
    public function importarArchivoExistente(SeccionArchivo $archivo, array $tablas, ?User $usuario): SeccionImportacion
    {
        return DB::transaction(function () use ($archivo, $tablas, $usuario): SeccionImportacion {
            $importacion = $this->importaciones->desdeTablas($archivo->seccion, $archivo, $tablas, SeccionImportacion::ORIGEN_ARCHIVO, $usuario);
            $hojas = [];

            foreach ($this->propuestas($importacion) as $indice => $propuesta) {
                if ($propuesta['vacia']) {
                    continue;
                }

                $hojas[$indice] = new OpcionesImportacion(
                    tabla: $indice,
                    filaEncabezado: $propuesta['fila_encabezado'],
                    mapeo: array_map('strval', $propuesta['mapeo']),
                    tipos: array_map(fn ($tipo) => TipoColumna::from($tipo), $propuesta['tipos']),
                    modo: ModoImportacion::Agregar,
                    llave: null,
                );
            }

            return $this->aplicar($importacion, new OpcionesLibro(ModoImportacion::Agregar, $hojas), $usuario);
        });
    }

    /**
     * @return list<string>
     */
    private function hojasYaCargadas(SeccionArchivo $archivo): array
    {
        $nombres = [];

        SeccionImportacion::query()
            ->where('seccion_archivo_id', $archivo->id)
            ->where('estado', EstadoImportacion::Aplicada->value)
            ->get(['id', 'resumen'])
            ->each(function (SeccionImportacion $importacion) use (&$nombres): void {
                $resumen = $importacion->resumen ?? [];
                $nombres = [...$nombres, ...array_column($resumen['hojas'] ?? [], 'nombre'), ...array_filter([$resumen['tabla'] ?? null])];
            });

        return $nombres;
    }

    /**
     * Resumen por hoja y total, sin escribir nada.
     *
     * @return array<string, mixed>
     */
    public function calcular(SeccionImportacion $importacion, OpcionesLibro $opciones): array
    {
        $this->importaciones->exigirVistaPrevia($importacion);
        $this->exigirHojas($importacion, $opciones);
        $destinos = $this->resolverDestinos($importacion, $opciones);

        $total = ['nuevas' => 0, 'actualizadas' => 0, 'sin_cambios' => 0, 'eliminadas' => 0];
        $hojas = [];

        foreach ($opciones->hojas as $indice => $hoja) {
            $nombre = $importacion->tablas[$indice]['nombre'];

            if (! array_key_exists($indice, $destinos)) {
                $hojas[] = ['tabla' => $indice, 'nombre' => $nombre, 'omitida' => true];

                continue;
            }

            $destino = $destinos[$indice];
            $plan = $this->planificador->planear($destino ?? new SeccionTabla, $importacion->tablas, $hoja);

            $linea = [
                'nuevas' => count($plan['nuevas']),
                'actualizadas' => count($plan['actualizadas']),
                'sin_cambios' => $plan['sin_cambios'],
                'eliminadas' => count($plan['eliminadas']),
            ];

            foreach ($linea as $clave => $valor) {
                $total[$clave] += $valor;
            }

            $hojas[] = ['tabla' => $indice, 'nombre' => $nombre, 'omitida' => false, 'destino_nuevo' => $destino === null,
                'columnas_nuevas' => count($plan['columnas_nuevas'])] + $linea;
        }

        return $total + ['hojas' => $hojas];
    }

    /**
     * La autorización (gestionar los datos de la sección) la exige el
     * controlador; todas las hojas caen dentro de esa misma sección.
     */
    public function aplicar(SeccionImportacion $importacion, OpcionesLibro $opciones, ?User $usuario): SeccionImportacion
    {
        $this->importaciones->exigirVistaPrevia($importacion);
        $this->exigirHojas($importacion, $opciones);
        $destinos = $this->resolverDestinos($importacion, $opciones);

        return DB::transaction(function () use ($importacion, $opciones, $usuario, $destinos): SeccionImportacion {
            // Bloqueo de fila: un doble clic en "Aplicar" no puede aplicar dos veces.
            $importacion = SeccionImportacion::query()->lockForUpdate()->findOrFail($importacion->id);
            $this->importaciones->exigirVistaPrevia($importacion);
            $ahora = now();

            $cambios = ['actualizadas' => [], 'eliminadas' => [], 'columnas_creadas' => [], 'tablas_creadas' => []];
            $resumen = ['nuevas' => 0, 'actualizadas' => 0, 'sin_cambios' => 0, 'eliminadas' => 0, 'columnas_nuevas' => 0, 'tabla' => null, 'hojas' => [], 'omitidas' => []];

            foreach ($opciones->hojas as $indice => $hoja) {
                if (array_key_exists($indice, $destinos)) {
                    $this->aplicarHoja($importacion, $hoja, $destinos[$indice], $usuario, $ahora, $cambios, $resumen);
                } else {
                    $resumen['omitidas'][] = $importacion->tablas[$indice]['nombre'];
                }
            }

            if ($resumen['hojas'] === []) {
                throw ValidationException::withMessages(['hojas' => __('Ninguna de las hojas elegidas tiene datos para cargar.')]);
            }

            $importacion->update([
                'estado' => EstadoImportacion::Aplicada,
                'modo' => $opciones->modo,
                'resumen' => $resumen,
                'cambios' => $cambios,
                'tablas' => [],
                'aplicada_at' => $ahora,
            ]);

            activity('seccion')
                ->causedBy($usuario)
                ->performedOn($importacion->seccion)
                ->event('importacion_aplicada')
                ->withProperties(['importacion_id' => $importacion->id, 'modo' => $opciones->modo->value, 'libro' => true, 'resumen' => $resumen])
                ->log('Importación de libro con varias hojas aplicada.');

            return $importacion;
        });
    }

    /**
     * Escribe una hoja en su tabla destino (la crea si no existía) y
     * acumula el resumen y el registro de cambios para deshacer.
     *
     * @param  array<string, mixed>  $cambios
     * @param  array<string, mixed>  $resumen
     */
    private function aplicarHoja(SeccionImportacion $importacion, OpcionesImportacion $hoja, ?SeccionTabla $destino, ?User $usuario, CarbonInterface $ahora, array &$cambios, array &$resumen): void
    {
        $nombre = $importacion->tablas[$hoja->tabla]['nombre'];

        if ($destino === null) {
            $destino = $this->crearTabla($importacion->seccion, Str::limit($nombre, self::MAX_NOMBRE, ''));
            $cambios['tablas_creadas'][] = $destino->id;
        }

        $plan = $this->planificador->planear($destino, $importacion->tablas, $hoja);
        $columnas = $this->importaciones->crearColumnas($destino, $plan['columnas_nuevas']);
        $this->importaciones->insertarFilas($destino, $importacion, $plan['nuevas'], $usuario, $ahora);
        $this->importaciones->actualizarFilas($plan['actualizadas'], $usuario, $ahora);
        $this->importaciones->eliminarFilas($plan['eliminadas'], $ahora);

        $linea = [
            'nuevas' => count($plan['nuevas']),
            'actualizadas' => count($plan['actualizadas']),
            'sin_cambios' => $plan['sin_cambios'],
            'eliminadas' => count($plan['eliminadas']),
            'columnas_nuevas' => count($columnas),
        ];

        foreach ($linea as $clave => $valor) {
            $resumen[$clave] += $valor;
        }

        $resumen['hojas'][] = ['nombre' => $nombre, 'tabla_id' => $destino->id] + $linea;
        $cambios['columnas_creadas'] = [...$cambios['columnas_creadas'], ...$columnas];
        $cambios['eliminadas'] = [...$cambios['eliminadas'], ...$plan['eliminadas']];
        $cambios['actualizadas'] += array_map(fn (array $cambio) => $cambio['antes'], $plan['actualizadas']);
    }

    /**
     * Tabla destino de cada hoja con datos que se va a cargar (índice de la
     * hoja => tabla existente de la sección o null si se creará); las hojas
     * sin datos no aparecen. Rechaza dos hojas que caerían en la misma tabla.
     *
     * @return array<int, ?SeccionTabla>
     */
    private function resolverDestinos(SeccionImportacion $importacion, OpcionesLibro $opciones): array
    {
        $destinos = [];
        $vistos = [];

        foreach ($opciones->hojas as $indice => $hoja) {
            $tabla = $importacion->tablas[$indice];

            if ($this->sinDatos($tabla['filas'], $hoja->filaEncabezado)) {
                continue;
            }

            $destino = $this->destinoExistente($importacion->seccion, $tabla['nombre']);
            // Misma regla de coincidencia que la base (mayúsculas/acentos) y el truncado del nombre.
            $llave = $destino !== null ? 'id:'.$destino->id : 'nombre:'.Texto::normalizar(Str::limit($tabla['nombre'], self::MAX_NOMBRE, ''));

            if (isset($vistos[$llave])) {
                throw ValidationException::withMessages(['hojas' => __('Las hojas ":a" y ":b" irían a la misma tabla. Desmarca una o cambia el nombre de la hoja en el documento.', ['a' => $vistos[$llave], 'b' => $tabla['nombre']])]);
            }

            $vistos[$llave] = $tabla['nombre'];
            $destinos[$indice] = $destino;
        }

        return $destinos;
    }

    /**
     * La tabla destino de una hoja: la tabla de la sección que ya se llama
     * como la hoja, o null si hay que crearla.
     */
    private function destinoExistente(Seccion $seccion, string $nombreHoja): ?SeccionTabla
    {
        return $seccion->tablas()->where('nombre', Str::limit($nombreHoja, self::MAX_NOMBRE, ''))->first();
    }

    /**
     * Tabla nueva para una hoja. Si la sección solo tiene la tabla por
     * defecto vacía (la que se crea sola al abrir una sección sin datos),
     * se reutiliza con el nombre de la hoja en lugar de dejarla sobrando.
     */
    private function crearTabla(Seccion $seccion, string $nombre): SeccionTabla
    {
        $vacia = $seccion->tablas()->where('nombre', SeccionTabla::NOMBRE_POR_DEFECTO)->get()
            // Filas dadas de baja cuentan: una carga previa podría restaurarlas en la tabla renombrada.
            ->first(fn (SeccionTabla $tabla) => $tabla->filas()->withTrashed()->doesntExist() && $tabla->columnas()->doesntExist());

        if ($vacia !== null) {
            $vacia->update(['nombre' => $nombre]);

            return $vacia;
        }

        return $seccion->tablas()->create(['nombre' => $nombre, 'orden' => (int) $seccion->tablas()->max('orden') + 1]);
    }

    /**
     * Hoja sin ninguna celda con dato debajo de los encabezados.
     *
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

    private function exigirHojas(SeccionImportacion $importacion, OpcionesLibro $opciones): void
    {
        if ($opciones->hojas === []) {
            throw ValidationException::withMessages(['hojas' => __('Elige al menos una hoja para cargar.')]);
        }

        if (count($opciones->hojas) > (int) config('secciones.max_hojas_importacion')) {
            throw ValidationException::withMessages(['hojas' => __('Se pueden cargar máximo :max hojas a la vez.', ['max' => config('secciones.max_hojas_importacion')])]);
        }

        foreach (array_keys($opciones->hojas) as $indice) {
            if (! isset($importacion->tablas[$indice])) {
                throw ValidationException::withMessages(['hojas' => __('Una de las hojas elegidas no existe en el documento.')]);
            }
        }
    }
}
