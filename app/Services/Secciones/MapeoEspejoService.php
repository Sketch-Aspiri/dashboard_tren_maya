<?php

namespace App\Services\Secciones;

use App\Enums\ModoImportacion;
use App\Models\SeccionArchivo;
use App\Models\SeccionColumna;
use App\Models\SeccionImportacion;
use App\Models\SeccionMapeo;
use App\Models\SeccionOrigen;
use App\Models\SeccionTabla;
use App\Models\User;
use App\Support\Texto;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lo que quien gestiona los datos decide sobre las tablas espejo: confirmar el mapeo
 * del primer Excel (una sola vez), editarlo, y aplicar o ignorar un cambio
 * masivo retenido. La sincronización diaria vive en TablasEspejoService.
 */
final class MapeoEspejoService
{
    public function __construct(
        private readonly TablasEspejoService $espejo,
        private readonly LibroImportacionService $libros,
        private readonly ImportacionService $importaciones,
        private readonly PreparadorTabla $preparador,
    ) {}

    /**
     * ¿Es la vista previa del primer mapeo de un Excel de la nube?
     */
    public function esVistaPrevia(SeccionImportacion $importacion): bool
    {
        return $importacion->origen === SeccionImportacion::ORIGEN_NUBE && $importacion->isVistaPrevia();
    }

    /**
     * Datos de la pantalla "Editar mapeo": por hoja mapeada, su tabla, las
     * columnas elegibles como llave y las primeras filas del documento.
     *
     * @return list<array{mapeo: SeccionMapeo, tabla: SeccionTabla, columnas: Collection<int, SeccionColumna>, primeras_filas: list<list<mixed>>, legible: bool}>
     */
    public function paraEdicion(SeccionOrigen $origen): array
    {
        $lecturas = [];
        $hojas = [];

        foreach ($origen->mapeos()->with('tabla', 'archivo')->orderBy('id')->get() as $mapeo) {
            if ($mapeo->tabla === null || $mapeo->tabla->trashed()) {
                continue;
            }

            $archivo = $mapeo->archivo;
            $lecturas[$mapeo->seccion_archivo_id] ??= $archivo === null || $archivo->trashed() ? null : $this->espejo->leerHojas($origen, $archivo);
            $hoja = $lecturas[$mapeo->seccion_archivo_id] === null ? null : $this->buscarHoja($lecturas[$mapeo->seccion_archivo_id], $mapeo->hoja);

            $hojas[] = [
                'mapeo' => $mapeo,
                'tabla' => $mapeo->tabla,
                'columnas' => $mapeo->tabla->columnas()->get(),
                'primeras_filas' => $hoja === null ? [] : array_slice($hoja['filas'], 0, 15),
                'legible' => $hoja !== null,
            ];
        }

        return $hojas;
    }

    /**
     * Aplica la vista previa con lo que el usuario confirmó, guarda un mapeo por
     * hoja, marca las tablas como espejo y, sin más vistas previas, activa el origen.
     */
    public function confirmar(SeccionImportacion $importacion, OpcionesLibro $opciones, User $usuario): SeccionImportacion
    {
        $archivo = $this->archivoDe($importacion);
        $origen = $archivo->origen ?? throw ValidationException::withMessages(['importacion' => __('El origen de esta carga ya no existe.')]);
        $contexto = $this->contexto($importacion, $opciones);

        return DB::transaction(function () use ($importacion, $opciones, $usuario, $archivo, $origen, $contexto): SeccionImportacion {
            $aplicada = $this->libros->aplicar($importacion, $opciones, $usuario);
            $guardados = [];

            foreach ($contexto as $indice => $hoja) {
                $entrada = collect($aplicada->resumen['hojas'] ?? [])->firstWhere('nombre', $hoja['nombre']);

                if ($entrada !== null) {
                    $guardados[] = $this->guardarMapeo($origen, $archivo, SeccionTabla::query()->findOrFail($entrada['tabla_id']), $hoja, $opciones->hojas[$indice], $opciones->modo, $usuario);
                }
            }

            $this->espejo->refrescarEstado($origen->refresh());

            activity('seccion')
                ->causedBy($usuario)
                ->performedOn($origen->seccion)
                ->event('mapeo_confirmado')
                ->withProperties(['origen' => $origen->nombre, 'documento' => $archivo->nombre_original, 'tablas' => $guardados])
                ->log('Mapeo de columnas confirmado: las tablas se mantendrán sincronizadas con la nube.');

            return $aplicada;
        });
    }

    /**
     * Descarta la vista previa (el origen deja de esperar si no hay otras).
     */
    public function descartar(SeccionImportacion $importacion): void
    {
        $origen = $this->archivoDe($importacion)->origen;
        $this->importaciones->descartar($importacion);

        if ($origen !== null) {
            $this->espejo->refrescarEstado($origen->refresh());
        }
    }

    /**
     * Cambia la fila de encabezados y la llave de varias hojas en una sola
     * transacción: si una falla, ninguna queda aplicada.
     *
     * @param  iterable<array{mapeo: SeccionMapeo, fila_encabezado: int, llave_clave: ?string}>  $cambios
     *
     * @throws ValidationException
     */
    public function actualizarVarios(iterable $cambios, User $usuario, bool $confirmarMasivo = false): void
    {
        DB::transaction(function () use ($cambios, $usuario, $confirmarMasivo): void {
            foreach ($cambios as $cambio) {
                $this->actualizar($cambio['mapeo'], $cambio['fila_encabezado'], $cambio['llave_clave'], $usuario, $confirmarMasivo);
            }
        });
    }

    /**
     * Cambia la fila de encabezados y la llave de una hoja y vuelve a aplicarla.
     * Si el cambio archivaría muchas filas (o vaciaría la tabla) no se aplica
     * hasta que el usuario lo confirme de forma explícita.
     *
     * @throws ValidationException
     */
    public function actualizar(SeccionMapeo $mapeo, int $filaEncabezado, ?string $llaveClave, User $usuario, bool $confirmarMasivo = false): void
    {
        $hoja = $this->hojaActual($mapeo);
        $tabla = $this->tablaDe($mapeo);

        if ($filaEncabezado < 0 || $filaEncabezado >= count($hoja['filas'])) {
            throw ValidationException::withMessages(['fila_encabezado' => __('La fila de encabezados no existe en la hoja.')]);
        }

        if ($llaveClave !== null) {
            $this->exigirLlaveValida($mapeo, $tabla, $hoja, $filaEncabezado, $llaveClave);
        }

        $original = $mapeo->only(['fila_encabezado', 'llave_clave', 'contenido_hash', 'retenido']);

        // Sin huella previa el mapeo se vuelve a aplicar aunque el Excel no haya cambiado.
        $mapeo->forceFill(['fila_encabezado' => $filaEncabezado, 'llave_clave' => $llaveClave, 'contenido_hash' => null]);
        $aviso = $this->espejo->aplicarMapeo($mapeo, $hoja, $mapeo->archivo, $usuario, $confirmarMasivo);

        if ($aviso !== null) {
            $retenido = $mapeo->retenido;
            $mapeo->forceFill($original)->save();

            throw ValidationException::withMessages(['mapeo' => $retenido === null
                ? $aviso
                : __('Este cambio dejaría «:hoja» con :n de :total filas archivadas y no se aplicó. Marca «Confirmo el cambio masivo» y guarda de nuevo si es lo que quieres.', [
                    'hoja' => $mapeo->hoja, 'n' => $retenido['archivar'] ?? 0, 'total' => $retenido['total'] ?? 0,
                ])]);
        }

        $mapeo->forceFill(['confirmado_por' => $usuario->id, 'confirmado_en' => now()])->save();

        activity('seccion')
            ->causedBy($usuario)
            ->performedOn($tabla->seccion)
            ->event('mapeo_confirmado')
            ->withProperties(['origen' => $mapeo->origen->nombre, 'hoja' => $mapeo->hoja, 'fila_encabezado' => $filaEncabezado, 'llave' => $llaveClave, 'confirmado_masivo' => $confirmarMasivo])
            ->log('Mapeo de columnas editado.');
    }

    /**
     * Aplica el cambio retenido tal como viene hoy en el documento.
     *
     * @throws ValidationException
     */
    public function aplicarRetenido(SeccionMapeo $mapeo, User $usuario): void
    {
        $archivo = $mapeo->archivo;
        $hojas = $archivo === null || $archivo->trashed() ? null : $this->espejo->leerHojas($mapeo->origen, $archivo);

        if ($hojas === null) {
            throw ValidationException::withMessages(['mapeo' => __('El documento ya no se puede leer; no se aplicó nada.')]);
        }

        $tabla = $this->tablaDe($mapeo);
        $aviso = $this->espejo->aplicarMapeo($mapeo, $this->buscarHoja($hojas, $mapeo->hoja), $archivo, $usuario, true);

        if ($aviso !== null) {
            throw ValidationException::withMessages(['mapeo' => $aviso]);
        }

        activity('seccion')
            ->causedBy($usuario)
            ->performedOn($tabla->seccion)
            ->event('cambio_masivo_aplicado')
            ->withProperties(['origen' => $mapeo->origen->nombre, 'hoja' => $mapeo->hoja])
            ->log('Cambio masivo aplicado.');
    }

    /**
     * Deja la tabla como está y recuerda el contenido ignorado para no volver a retenerlo.
     */
    public function ignorarRetenido(SeccionMapeo $mapeo, User $usuario): void
    {
        $tabla = $this->tablaDe($mapeo);
        $archivo = $mapeo->archivo;
        $hojas = $archivo === null || $archivo->trashed() ? null : $this->espejo->leerHojas($mapeo->origen, $archivo);
        $hoja = $hojas === null ? null : $this->buscarHoja($hojas, $mapeo->hoja);

        $mapeo->forceFill([
            'contenido_hash' => $hoja === null ? null : $this->espejo->huella($hoja['filas']),
            'retenido' => null,
        ])->save();

        activity('seccion')
            ->causedBy($usuario)
            ->performedOn($tabla->seccion)
            ->event('cambio_masivo_ignorado')
            ->withProperties(['origen' => $mapeo->origen->nombre, 'hoja' => $mapeo->hoja])
            ->log('Cambio masivo ignorado: la tabla conserva sus datos.');
    }

    /**
     * Hoja y fila de encabezados de cada hoja incluida, leídas antes de aplicar
     * (la aplicación vacía el contenido de la vista previa).
     *
     * @return array<int, array{nombre: string, hoja: string, encabezados: list<string>, con_datos: array<int, bool>, huella: string}>
     */
    private function contexto(SeccionImportacion $importacion, OpcionesLibro $opciones): array
    {
        $contexto = [];

        foreach ($opciones->hojas as $indice => $hoja) {
            $tabla = $importacion->tablas[$indice] ?? throw ValidationException::withMessages(['hojas' => __('Una de las hojas elegidas no existe en el documento.')]);
            $datos = $this->preparador->datos($tabla['filas'], $hoja->filaEncabezado);
            $encabezados = $this->preparador->encabezados($tabla['filas'], $hoja->filaEncabezado);

            $contexto[$indice] = [
                'nombre' => $tabla['nombre'],
                'hoja' => $tabla['hoja'] ?? $tabla['nombre'],
                'encabezados' => $encabezados,
                'con_datos' => array_map(fn (int $columna): bool => array_filter(array_column($datos, $columna), fn ($valor) => $valor !== null && $valor !== '') !== [], array_keys($encabezados)),
                'huella' => $this->espejo->huella($tabla['filas']),
            ];
        }

        return $contexto;
    }

    /**
     * @param  array{nombre: string, hoja: string, encabezados: list<string>, con_datos: array<int, bool>, huella: string}  $hoja
     * @return array{tabla: string, hoja: string}
     */
    private function guardarMapeo(SeccionOrigen $origen, SeccionArchivo $archivo, SeccionTabla $tabla, array $hoja, OpcionesImportacion $opciones, ModoImportacion $modo, User $usuario): array
    {
        $tabla->forceFill(['seccion_origen_id' => $origen->id])->save();

        $columnas = [];
        $claves = [];

        foreach ($hoja['encabezados'] as $indice => $encabezado) {
            $destino = $opciones->mapeo[$indice] ?? OpcionesImportacion::DESTINO_IGNORAR;
            $clave = match (true) {
                $destino === OpcionesImportacion::DESTINO_NUEVA => $tabla->columnas()->where('nombre', $encabezado)->orderByDesc('id')->value('clave'),
                $destino === OpcionesImportacion::DESTINO_IGNORAR => ($hoja['con_datos'][$indice] ?? false) ? SeccionMapeo::IGNORAR : null,
                default => $destino,
            };
            $claves[$indice] = $clave;

            if ($clave !== null) {
                $columnas[] = ['encabezado_normalizado' => Texto::normalizar($encabezado), 'clave' => $clave];
            }
        }

        $llave = $modo === ModoImportacion::Actualizar && $opciones->llave !== null ? ($claves[$opciones->llave] ?? null) : null;

        $mapeo = SeccionMapeo::query()->where('seccion_archivo_id', $archivo->id)->where('hoja', $hoja['hoja'])->first() ?? new SeccionMapeo;
        $mapeo->forceFill([
            'seccion_origen_id' => $origen->id,
            'seccion_archivo_id' => $archivo->id,
            'hoja' => $hoja['hoja'],
            'seccion_tabla_id' => $tabla->id,
            'fila_encabezado' => $opciones->filaEncabezado,
            'columnas' => $columnas,
            'llave_clave' => $llave === SeccionMapeo::IGNORAR ? null : $llave,
            'contenido_hash' => $hoja['huella'],
            'retenido' => null,
            'confirmado_por' => $usuario->id,
            'confirmado_en' => now(),
        ])->save();

        return ['tabla' => $tabla->nombre, 'hoja' => $hoja['hoja']];
    }

    private function tablaDe(SeccionMapeo $mapeo): SeccionTabla
    {
        return $mapeo->tabla ?? throw ValidationException::withMessages(['mapeo' => __('La tabla de la hoja «:hoja» ya no existe; desvincula y vuelve a vincular el origen.', ['hoja' => $mapeo->hoja])]);
    }

    private function archivoDe(SeccionImportacion $importacion): SeccionArchivo
    {
        return SeccionArchivo::withTrashed()->with('origen')->find($importacion->seccion_archivo_id)
            ?? throw ValidationException::withMessages(['importacion' => __('El documento de esta carga ya no existe.')]);
    }

    /**
     * @return array{nombre: string, hoja: string, filas: list<list<mixed>>}
     */
    private function hojaActual(SeccionMapeo $mapeo): array
    {
        $archivo = $mapeo->archivo;
        $hojas = $archivo === null || $archivo->trashed() ? null : $this->espejo->leerHojas($mapeo->origen, $archivo);

        return ($hojas === null ? null : $this->buscarHoja($hojas, $mapeo->hoja))
            ?? throw ValidationException::withMessages(['mapeo' => __('La hoja «:hoja» ya no está en el documento: no se puede editar su mapeo.', ['hoja' => $mapeo->hoja])]);
    }

    /**
     * @param  list<array{nombre: string, hoja: string, filas: list<list<mixed>>}>  $hojas
     * @return array{nombre: string, hoja: string, filas: list<list<mixed>>}|null
     */
    private function buscarHoja(array $hojas, string $nombre): ?array
    {
        return collect($hojas)->firstWhere('hoja', $nombre);
    }

    /**
     * La llave debe ser una columna de la tabla que el Excel siga trayendo con la nueva fila de encabezados.
     *
     * @param  array{filas: list<list<mixed>>}  $hoja
     */
    private function exigirLlaveValida(SeccionMapeo $mapeo, SeccionTabla $tabla, array $hoja, int $filaEncabezado, string $llaveClave): void
    {
        $normalizados = array_map(fn (string $encabezado): string => Texto::normalizar($encabezado), $this->preparador->encabezados($hoja['filas'], $filaEncabezado));
        $viene = collect($mapeo->columnas ?? [])->contains(fn (array $c): bool => $c['clave'] === $llaveClave && in_array($c['encabezado_normalizado'], $normalizados, true));

        if (! $viene || ! $tabla->columnas()->where('clave', $llaveClave)->exists()) {
            throw ValidationException::withMessages(['llave' => __('La columna llave debe ser una columna de la tabla que el Excel traiga en la fila de encabezados elegida.')]);
        }
    }
}
