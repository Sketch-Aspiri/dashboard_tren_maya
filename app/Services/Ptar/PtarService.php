<?php

namespace App\Services\Ptar;

use App\Models\Estacion;
use App\Models\PtarActividad;
use App\Models\PtarEquipo;
use App\Models\PtarEvidencia;
use App\Models\PtarHallazgo;
use App\Models\PtarPlanta;
use App\Models\PtarRegistro;
use App\Models\User;
use App\Services\Ecosistema\AvisosEcosistema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Lógica de PTAR: registro mensual por estación (hereda del mes anterior), captura de equipos, actividades y
 * fotos, envío, revisión de la Zona y tablero con semáforo.
 */
final class PtarService
{
    public const VERDE = 'verde';

    public const AMBAR = 'ambar';

    public const ROJO = 'rojo';

    public const GRIS = 'gris';

    public function __construct(private readonly AvisosEcosistema $avisos) {}

    /** @return array{anio: int, mes: int} */
    public function mesActual(): array
    {
        $hoy = now('America/Cancun');

        return ['anio' => $hoy->year, 'mes' => $hoy->month];
    }

    /** Registro existente del mes de una estación (sin crearlo). */
    public function buscar(Estacion $estacion, int $anio, int $mes): ?PtarRegistro
    {
        return PtarRegistro::query()->where(['estacion_id' => $estacion->id, 'anio' => $anio, 'mes' => $mes])->first();
    }

    /**
     * Abre (o crea en borrador) el registro del mes de una estación: hereda modo, operador, estatus de los equipos
     * y actividades (con su última fecha) del último registro anterior; las observaciones empiezan vacías.
     */
    public function obtenerOCrear(Estacion $estacion, int $anio, int $mes, User $user): PtarRegistro
    {
        $previo = PtarRegistro::query()->where('estacion_id', $estacion->id)
            ->whereRaw('(anio * 12 + mes) < ?', [$anio * 12 + $mes])->orderByDesc('anio')->orderByDesc('mes')
            ->with(['equipos', 'actividades'])->first();

        return DB::transaction(function () use ($estacion, $anio, $mes, $user, $previo) {
            $registro = PtarRegistro::query()->firstOrCreate(
                ['estacion_id' => $estacion->id, 'anio' => $anio, 'mes' => $mes],
                ['estado' => PtarRegistro::BORRADOR] + ($previo?->only(['modo', 'modo_detalle', 'operador']) ?? []),
            );

            if ($registro->wasRecentlyCreated) {
                $this->sembrarDetalle($registro, $previo);
                activity('ptar')->performedOn($registro)->causedBy($user)->log('creado');
            }

            return $registro;
        });
    }

    private function sembrarDetalle(PtarRegistro $registro, ?PtarRegistro $previo): void
    {
        $estatus = $previo?->equipos->keyBy('equipo');

        foreach (array_keys(config('ptar.equipos')) as $clave) {
            $registro->equipos()->create(['equipo' => $clave, 'estatus' => $estatus?->get($clave)?->estatus ?? PtarEquipo::SIN_DATO]);
        }

        $nombres = $previo !== null && $previo->actividades->isNotEmpty()
            ? $previo->actividades->pluck('actividad')->all()
            : array_slice(config('ptar.actividades'), 0, 3);
        $fechas = $previo?->actividades->keyBy('actividad');

        foreach ($nombres as $nombre) {
            $registro->actividades()->create(['actividad' => $nombre, 'ultima_fecha' => $fechas?->get($nombre)?->ultima_fecha]);
        }
    }

    /**
     * La estación guarda su captura del mes (solo en borrador u observada).
     *
     * @param  array<string, mixed>  $datos  Salida validada de GuardarPtarRequest.
     */
    public function guardar(PtarRegistro $registro, array $datos, User $user): PtarRegistro
    {
        return DB::transaction(function () use ($registro, $datos, $user) {
            $registro = $this->bloquearEditable($registro);

            $registro->update(array_intersect_key($datos, array_flip(['modo', 'modo_detalle', 'operador', 'observaciones_equipos', 'observaciones_generales'])));

            foreach ($registro->equipos as $equipo) {
                $fila = $datos['equipos'][$equipo->equipo] ?? null;
                if (is_array($fila)) {
                    $equipo->update(['estatus' => $fila['estatus'], 'observaciones' => $fila['observaciones'] ?? null]);
                }
            }

            foreach ($registro->actividades as $actividad) {
                $fila = $datos['actividades'][$actividad->id] ?? null;
                if (is_array($fila)) {
                    $actividad->update(['ultima_fecha' => $fila['ultima_fecha'] ?: null, 'nota' => $fila['nota'] ?? null]);
                }
            }

            activity('ptar')->performedOn($registro)->causedBy($user)->log('captura guardada');

            return $registro->refresh();
        });
    }

    public function agregarEvidencia(PtarRegistro $registro, UploadedFile $foto, ?string $pie, User $user): PtarEvidencia
    {
        return DB::transaction(function () use ($registro, $foto, $pie, $user) {
            $registro = $this->bloquearEditable($registro);

            if ($registro->evidencias()->count() >= (int) config('ptar.max_evidencias')) {
                throw ValidationException::withMessages(['foto' => __('Ya subiste el máximo de :n fotos del mes.', ['n' => config('ptar.max_evidencias')])]);
            }

            return $registro->evidencias()->create([
                'ruta' => $foto->store('ptar/evidencias', 'local'),
                'nombre' => mb_substr($foto->getClientOriginalName(), 0, 255),
                'pie' => $pie,
                'origen' => PtarEvidencia::CAPTURA,
                'capturo_id' => $user->id,
            ]);
        });
    }

    public function quitarEvidencia(PtarEvidencia $evidencia, User $user): void
    {
        DB::transaction(function () use ($evidencia, $user) {
            $registro = $this->bloquearEditable($evidencia->registro);

            Storage::disk('local')->delete($evidencia->ruta);
            activity('ptar')->performedOn($registro)->causedBy($user)->withProperties(['nombre' => $evidencia->nombre])->log('foto eliminada');
            $evidencia->delete();
        });
    }

    public function enviar(PtarRegistro $registro, User $user): PtarRegistro
    {
        if ($registro->modo === PtarRegistro::MODO_SIN_DATO) {
            throw ValidationException::withMessages(['envio' => __('Indica el modo de operación de la planta antes de enviar.')]);
        }

        if ($registro->evidencias()->doesntExist()) {
            throw ValidationException::withMessages(['envio' => __('Sube al menos una foto de evidencia antes de enviar.')]);
        }

        $registro->update(['estado' => PtarRegistro::ENVIADA, 'enviada_at' => now()]);
        activity('ptar')->performedOn($registro)->causedBy($user)->log('enviada');
        $this->avisos->ptarEnviada($registro->loadMissing('estacion:id,nombre'), $user);

        return $registro;
    }

    /**
     * Decisión de la Zona: devolver (con comentario) o aprobar.
     *
     * @param  array{accion: string, comentario_zona?: string|null}  $datos
     */
    public function revisar(PtarRegistro $registro, array $datos, User $user): PtarRegistro
    {
        $aprobar = $datos['accion'] === 'aprobar';

        $registro->update([
            'estado' => $aprobar ? PtarRegistro::APROBADA : PtarRegistro::OBSERVADA,
            'comentario_zona' => $datos['comentario_zona'] ?? null,
            'revisada_por' => $user->id,
            'revisada_at' => now(),
        ]);

        activity('ptar')->performedOn($registro)->causedBy($user)->withProperties(['accion' => $datos['accion']])->log('revisada: '.$datos['accion']);
        $this->avisos->ptarRevisada($registro->loadMissing('estacion:id,nombre'));

        return $registro->refresh();
    }

    /**
     * Semáforo de una planta en el mes: gris sin captura; rojo si la planta o algún equipo está fuera de servicio;
     * ámbar si opera manual o con algún equipo parcial/sin dato; verde si todo opera en automático.
     */
    public function semaforo(?PtarRegistro $registro): string
    {
        if ($registro === null || $registro->modo === PtarRegistro::MODO_SIN_DATO) {
            return self::GRIS;
        }

        $estatus = $registro->equipos->pluck('estatus');

        if ($registro->modo === PtarRegistro::MODO_FUERA_SERVICIO || $estatus->contains(PtarEquipo::FUERA_SERVICIO)) {
            return self::ROJO;
        }

        if ($registro->modo === PtarRegistro::MODO_MANUAL || $estatus->contains(PtarEquipo::PARCIAL) || $estatus->contains(PtarEquipo::SIN_DATO)) {
            return self::AMBAR;
        }

        return self::VERDE;
    }

    /**
     * Una fila por planta activa, en el orden del catálogo, con el registro del mes (o null), su semáforo,
     * las actividades vencidas y los hallazgos abiertos.
     *
     * @return Collection<int, array{planta: PtarPlanta, estacion: Estacion, registro: PtarRegistro|null, semaforo: string, vencidas: int, hallazgos_abiertos: int}>
     */
    public function tablero(int $anio, int $mes): Collection
    {
        PtarPlanta::sincronizar();

        $plantas = PtarPlanta::query()->where('activa', true)->with('estacion')->get()
            ->sortBy(fn (PtarPlanta $planta) => $planta->numero ?? PHP_INT_MAX)->values();

        $registros = PtarRegistro::query()->with(['equipos', 'actividades', 'evidencias'])->where('anio', $anio)->where('mes', $mes)
            ->whereIn('estacion_id', $plantas->pluck('estacion_id'))->get()->keyBy('estacion_id');

        $abiertos = PtarHallazgo::query()->where('estado', '!=', PtarHallazgo::CERRADO)
            ->selectRaw('planta_id, count(*) as total')->groupBy('planta_id')->pluck('total', 'planta_id');

        return $plantas->map(function (PtarPlanta $planta) use ($registros, $abiertos) {
            $registro = $registros->get($planta->estacion_id);

            return [
                'planta' => $planta,
                'estacion' => $planta->estacion,
                'registro' => $registro,
                'semaforo' => $this->semaforo($registro),
                'vencidas' => $registro?->actividades->filter(fn (PtarActividad $a) => $a->estaVencida())->count() ?? 0,
                'hallazgos_abiertos' => (int) ($abiertos[$planta->id] ?? 0),
            ];
        });
    }

    private function bloquearEditable(PtarRegistro $registro): PtarRegistro
    {
        $registro = PtarRegistro::query()->lockForUpdate()->with(['equipos', 'actividades'])->findOrFail($registro->id);

        if (! $registro->esEditablePorEstacion()) {
            throw ValidationException::withMessages(['envio' => __('Este registro ya se envió; no se puede modificar.')]);
        }

        return $registro;
    }
}
