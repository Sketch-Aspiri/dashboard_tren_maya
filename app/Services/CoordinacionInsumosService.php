<?php

namespace App\Services;

use App\Models\Estacion;
use App\Models\SolicitudInsumo;
use App\Models\SolicitudInsumoPartida;
use App\Models\User;
use App\Services\Ecosistema\ActividadesEcosistema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Ciclo con la Coordinación: registra el envío del ANEXO (con su número de
 * oficio) y aplica la respuesta, un Excel con el mismo formato en el que la
 * Coordinación puede corregir la partida presupuestal de cada renglón
 * (columna P.Ptal/Fin), quitar renglones, agregar otros o cambiar cantidades
 * y precios. El documento devuelto es la fuente de verdad.
 */
final class CoordinacionInsumosService
{
    private const MAX_FILAS = 3000;

    /** @var array<string, string>|null Código de estación por nombre (solo letras), cargado al primer uso. */
    private ?array $estacionesPorNombre = null;

    public function __construct(
        private readonly CatalogoInsumoService $anexos,
        private readonly ActividadesEcosistema $actividades,
    ) {}

    /**
     * Las solicitudes APROBADAS del mes pasan a "enviada a Coordinación" con
     * el número de oficio. Devuelve cuántas se enviaron.
     */
    public function registrarEnvio(int $anio, int $mes, string $oficio, User $user): int
    {
        $solicitudes = SolicitudInsumo::query()->where('anio', $anio)->where('mes', $mes)
            ->where('estado', SolicitudInsumo::APROBADA)->get();

        if ($solicitudes->isEmpty()) {
            throw ValidationException::withMessages([
                'oficio' => __('No hay solicitudes aprobadas de ese mes para enviar a la Coordinación.'),
            ]);
        }

        DB::transaction(function () use ($solicitudes, $oficio) {
            foreach ($solicitudes as $solicitud) {
                $solicitud->update([
                    'estado' => SolicitudInsumo::ENVIADA_COORDINACION,
                    'oficio_numero' => $oficio,
                    'enviada_coordinacion_at' => now(),
                ]);
            }
        });

        activity('solicitud_insumo')->causedBy($user)
            ->withProperties(['anio' => $anio, 'mes' => $mes, 'oficio' => $oficio, 'solicitudes' => $solicitudes->count()])
            ->log('envio a coordinacion registrado');

        return $solicitudes->count();
    }

    /**
     * Aplica el Excel devuelto por la Coordinación a las solicitudes enviadas
     * del mes. Solo toca las estaciones cuyo bloque trae renglones.
     *
     * @return array{
     *     solicitudes: int, actualizadas: int,
     *     quitadas: list<string>, agregadas: list<string>, cambios: list<string>,
     *     sin_bloque: list<string>, sin_solicitud: list<string>, sin_renglones: list<string>
     * }
     */
    public function importarRespuesta(int $anio, int $mes, string $ruta, User $user): array
    {
        $bloques = $this->leerBloques($this->anexos->abrirHojaAnexo($ruta));

        if ($bloques === []) {
            throw ValidationException::withMessages([
                'archivo' => __('No se encontraron bloques de estación en el archivo. Verifica que sea el ANEXO devuelto por la Coordinación.'),
            ]);
        }

        $solicitudes = SolicitudInsumo::query()->with(['partidas', 'estacion'])
            ->where('anio', $anio)->where('mes', $mes)
            ->whereIn('estado', [SolicitudInsumo::ENVIADA_COORDINACION, SolicitudInsumo::DEVUELTA])
            ->get()->keyBy(fn (SolicitudInsumo $s) => (string) $s->estacion->codigo);

        $resumen = ['solicitudes' => 0, 'actualizadas' => 0, 'quitadas' => [], 'agregadas' => [], 'cambios' => [], 'sin_bloque' => [], 'sin_solicitud' => [], 'sin_renglones' => []];

        /** @var list<SolicitudInsumo> $devueltas */
        $devueltas = [];

        DB::transaction(function () use ($bloques, $solicitudes, &$resumen, &$devueltas) {
            foreach ($bloques as $codigo => $bloque) {
                $solicitud = $solicitudes->get((string) $codigo);

                if ($solicitud === null) {
                    $resumen['sin_solicitud'][] = $bloque['titulo'].(str_starts_with((string) $codigo, 'sin_reconocer_') ? ' (no se reconoció la estación)' : '');

                    continue;
                }

                if ($bloque['renglones'] === []) {
                    $resumen['sin_renglones'][] = $solicitud->estacion->nombre;

                    continue;
                }

                $this->aplicarBloque($solicitud, $bloque['renglones'], $resumen);
                $solicitud->update(['estado' => SolicitudInsumo::DEVUELTA, 'devuelta_at' => now()]);
                $devueltas[] = $solicitud;
                $resumen['solicitudes']++;
            }

            foreach ($solicitudes as $codigo => $solicitud) {
                if (! isset($bloques[$codigo])) {
                    $resumen['sin_bloque'][] = $solicitud->estacion->nombre;
                }
            }
        });

        // Una actividad en Tickets por cada estación a la que Coordinación devolvió su solicitud (tras el commit).
        foreach ($devueltas as $solicitud) {
            $this->actividades->solicitudDevuelta($solicitud);
        }

        activity('solicitud_insumo')->causedBy($user)
            ->withProperties(['anio' => $anio, 'mes' => $mes] + array_map(fn ($v) => is_array($v) ? count($v) : $v, $resumen))
            ->log('respuesta de coordinacion importada');

        return $resumen;
    }

    /**
     * @param  list<array{numero: int, cantidad: float|null, unidad: string, descripcion: string, precio: float|null, p_ptal: string|null, p_fin: string|null, justificacion: string}>  $renglones
     * @param  array<string, mixed>  $resumen
     */
    private function aplicarBloque(SolicitudInsumo $solicitud, array $renglones, array &$resumen): void
    {
        $nombre = $solicitud->estacion->nombre;
        $existentes = $solicitud->partidas->keyBy(fn (SolicitudInsumoPartida $p) => $this->clave($p->descripcion));
        $vistas = [];
        $siguiente = (int) $solicitud->partidas->max('numero') + 1;

        foreach ($renglones as $renglon) {
            $clave = $this->clave($renglon['descripcion']);
            $partidaFinal = $renglon['p_fin'] ?? $renglon['p_ptal'];
            $partida = $existentes->get($clave);

            if ($partida === null) {
                if ($renglon['cantidad'] === null) {
                    continue;
                }

                SolicitudInsumoPartida::query()->create([
                    'solicitud_insumo_id' => $solicitud->id,
                    'numero' => $siguiente++,
                    'cantidad' => $renglon['cantidad'],
                    'unidad' => $renglon['unidad'] ?: 'Pieza',
                    'descripcion' => $renglon['descripcion'],
                    'justificacion' => $renglon['justificacion'] ?: null,
                    'precio_unitario' => $renglon['precio'],
                    'partida_presupuestal' => $renglon['p_ptal'],
                    'partida_final' => $partidaFinal,
                    'agregada_por_coordinacion' => true,
                ]);
                $resumen['agregadas'][] = $nombre.': '.$renglon['descripcion'];
                $vistas[$clave] = true;

                continue;
            }

            $vistas[$clave] = true;
            $cambios = ['eliminada_por_coordinacion' => false];

            if ($partidaFinal !== null && $partidaFinal !== $partida->partida_final) {
                $cambios['partida_final'] = $partidaFinal;
            }

            if ($renglon['cantidad'] !== null && round($renglon['cantidad'], 2) !== round((float) $partida->cantidad, 2)) {
                $resumen['cambios'][] = $nombre.': '.$partida->descripcion.' — cantidad '.(float) $partida->cantidad.' → '.$renglon['cantidad'];
                $cambios['cantidad'] = $renglon['cantidad'];
            }

            if ($renglon['precio'] !== null && round($renglon['precio'], 2) !== round((float) $partida->precio_unitario, 2)) {
                $resumen['cambios'][] = $nombre.': '.$partida->descripcion.' — precio '.number_format((float) $partida->precio_unitario, 2).' → '.number_format($renglon['precio'], 2);
                $cambios['precio_unitario'] = $renglon['precio'];
            }

            $partida->update($cambios);
            $resumen['actualizadas']++;
        }

        foreach ($solicitud->partidas as $partida) {
            if (! isset($vistas[$this->clave($partida->descripcion)]) && ! $partida->eliminada_por_coordinacion) {
                $partida->update(['eliminada_por_coordinacion' => true]);
                $resumen['quitadas'][] = $nombre.': '.$partida->descripcion;
            }
        }
    }

    /**
     * Bloques del ANEXO por código de estación. Un bloque empieza en una fila
     * cuya columna B es "23_PUERTO MORELOS" y sigue hasta el siguiente.
     *
     * @return array<string, array{titulo: string, renglones: list<array<string, mixed>>}>
     */
    private function leerBloques(Worksheet $hoja): array
    {
        $bloques = [];
        $actual = null;
        $sinReconocer = 0;
        $limite = min($hoja->getHighestRow(), self::MAX_FILAS);

        for ($i = 1; $i <= $limite; $i++) {
            $titulo = trim((string) $hoja->getCell('B'.$i)->getValue());

            if ($this->esTituloDeBloque($hoja, $i, $titulo)) {
                // Un título que no se reconoce abre su propio bloque: sus renglones
                // nunca se pegan a la estación anterior.
                $actual = $this->codigoDeTitulo($titulo) ?? 'sin_reconocer_'.(++$sinReconocer);
                $bloques[$actual] ??= ['titulo' => $titulo, 'renglones' => []];

                continue;
            }

            $renglon = $actual === null ? null : $this->leerRenglon($hoja, $i);

            if ($renglon !== null) {
                $bloques[$actual]['renglones'][] = $renglon;
            }
        }

        return $bloques;
    }

    /**
     * Un bloque empieza en una fila de texto en la columna B seguida, una o dos
     * filas abajo, del encabezado de la tabla ("N°"), sin importar cómo esté
     * escrito el nombre de la estación.
     */
    private function esTituloDeBloque(Worksheet $hoja, int $fila, string $titulo): bool
    {
        if ($titulo === '' || is_numeric($titulo) || $titulo === 'N°') {
            return false;
        }

        // Estación reconocible por código o nombre; si no, el patrón título / gerente / encabezado.
        return $this->codigoDeTitulo($titulo) !== null
            || trim((string) $hoja->getCell('B'.($fila + 2))->getValue()) === 'N°';
    }

    /**
     * Código de estación de un título: los dos dígitos del principio ("23_",
     * "26 _  Tulum", "29 BACALAR") o, si no trae, el nombre de una estación
     * ("Playa del Carmen"). null si no se reconoce.
     */
    private function codigoDeTitulo(string $titulo): ?string
    {
        if (preg_match('/^\s*(\d{2})(?!\d)/u', $titulo, $m) === 1) {
            return $m[1];
        }

        $buscado = $this->soloLetras($titulo);

        if ($buscado === '') {
            return null;
        }

        $this->estacionesPorNombre ??= Estacion::query()->whereNotNull('codigo')->get(['nombre', 'codigo'])
            ->mapWithKeys(fn (Estacion $e) => [$this->soloLetras($e->nombre) => (string) $e->codigo])->all();

        return $this->estacionesPorNombre[$buscado] ?? null;
    }

    private function soloLetras(string $texto): string
    {
        return (string) preg_replace('/[^a-z]/', '', Str::ascii(mb_strtolower($texto)));
    }

    /**
     * @return array{numero: int, cantidad: float|null, unidad: string, descripcion: string, precio: float|null, p_ptal: string|null, p_fin: string|null, justificacion: string}|null
     */
    private function leerRenglon(Worksheet $hoja, int $fila): ?array
    {
        $numero = $hoja->getCell('B'.$fila)->getValue();
        $descripcion = $this->limpiar($hoja->getCell('E'.$fila)->getValue());

        if (! is_numeric($numero) || $descripcion === '' || preg_match('/^(no realizaron solicitud|solicitud .* pendiente)/iu', $descripcion) === 1) {
            return null;
        }

        $cantidad = $hoja->getCell('C'.$fila)->getValue();
        $precio = $hoja->getCell('F'.$fila)->getValue();

        return [
            'numero' => (int) $numero,
            'cantidad' => is_numeric($cantidad) && (float) $cantidad > 0 ? round((float) $cantidad, 2) : null,
            'unidad' => mb_substr($this->limpiar($hoja->getCell('D'.$fila)->getValue()), 0, 30),
            'descripcion' => mb_substr($descripcion, 0, 255),
            'precio' => is_numeric($precio) && (float) $precio > 0 ? round((float) $precio, 2) : null,
            'p_ptal' => $this->partida($hoja->getCell('H'.$fila)->getValue()),
            'p_fin' => $this->partida($hoja->getCell('I'.$fila)->getValue()),
            'justificacion' => mb_substr($this->limpiar($hoja->getCell('J'.$fila)->getValue()), 0, 2000),
        ];
    }

    private function clave(string $descripcion): string
    {
        return Str::ascii(mb_strtolower($this->limpiar($descripcion)));
    }

    private function limpiar(mixed $valor): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $valor));
    }

    private function partida(mixed $valor): ?string
    {
        $texto = $this->limpiar($valor);

        return preg_match('/^\d{5}$/', $texto) === 1 ? $texto : null;
    }
}
