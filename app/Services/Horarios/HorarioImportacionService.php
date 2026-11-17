<?php

namespace App\Services\Horarios;

use App\Models\Empleado;
use App\Models\Estacion;
use App\Models\HorarioPeriodo;
use App\Models\HorarioTurno;
use App\Models\User;
use App\Services\Ecosistema\AvisosEcosistema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sube el horario oficial: vista previa (nada se guarda en la tabla) y luego
 * aplicar, que lo publica y avisa a cada estación que tiene turnos. Resubir el
 * mismo mes crea una versión nueva y conserva los cambios ya autorizados.
 */
final class HorarioImportacionService
{
    private const TTL_MINUTOS = 60;

    public function __construct(
        private readonly HorarioLector $lector,
        private readonly HorarioSolicitudService $solicitudes,
        private readonly AvisosEcosistema $avisos,
    ) {}

    /**
     * Guarda el archivo en disco privado y devuelve el resumen de lo que se importaría.
     *
     * @return array{token: string, resumen: array<string, mixed>}
     */
    public function previsualizar(UploadedFile $archivo, User $user): array
    {
        $token = (string) Str::uuid();
        $ruta = $this->rutaPendiente($token);
        Storage::disk('local')->putFileAs('horarios/pendiente', $archivo, $token.'.xlsx');

        $lectura = $this->lector->leer(Storage::disk('local')->path($ruta));

        if ($lectura['filas'] === [] || $lectura['periodo'] === null) {
            Storage::disk('local')->delete($ruta);

            throw ValidationException::withMessages([
                'archivo' => $lectura['errores'][0] ?? __('No se encontraron filas de horario. Revisa que las hojas traigan No. Empleado, Nombre completo, Fecha y Jornada Laboral.'),
            ]);
        }

        $resumen = $this->resumen($lectura, $archivo->getClientOriginalName());
        Cache::put($this->clave($token), ['usuario' => $user->id, 'resumen' => $resumen], now()->addMinutes(self::TTL_MINUTOS));

        return ['token' => $token, 'resumen' => $resumen];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resumenPendiente(string $token, User $user): ?array
    {
        $dato = Cache::get($this->clave($token));

        return is_array($dato) && ($dato['usuario'] ?? null) === $user->id ? $dato['resumen'] : null;
    }

    public function descartar(string $token): void
    {
        Storage::disk('local')->delete($this->rutaPendiente($token));
        Cache::forget($this->clave($token));
    }

    /**
     * @return array{periodo: HorarioPeriodo, turnos: int, estaciones: int, sin_aplicar: int}
     */
    public function aplicar(string $token, User $user): array
    {
        $dato = Cache::get($this->clave($token));

        if (! is_array($dato) || ($dato['usuario'] ?? null) !== $user->id || ! Storage::disk('local')->exists($this->rutaPendiente($token))) {
            throw ValidationException::withMessages(['archivo' => __('La vista previa venció. Vuelve a subir el archivo.')]);
        }

        $rutaFisica = Storage::disk('local')->path($this->rutaPendiente($token));
        $lectura = $this->lector->leer($rutaFisica);
        $hash = hash_file('sha256', $rutaFisica);
        $nombre = (string) ($dato['resumen']['archivo'] ?? 'horario.xlsx');

        $anio = $lectura['periodo']['anio'];
        $mes = $lectura['periodo']['mes'];

        $vigente = HorarioPeriodo::query()->where('anio', $anio)->where('mes', $mes)->where('estado', HorarioPeriodo::VIGENTE)->first();

        if ($vigente !== null && $vigente->archivo_hash === $hash) {
            throw ValidationException::withMessages(['archivo' => __('Este archivo es idéntico al horario vigente de ese mes.')]);
        }

        $resultado = DB::transaction(function () use ($lectura, $vigente, $anio, $mes, $hash, $nombre, $token, $user) {
            $version = (int) HorarioPeriodo::query()->where('anio', $anio)->where('mes', $mes)->max('version') + 1;
            $destino = sprintf('horarios/oficiales/%d-%02d-v%d.xlsx', $anio, $mes, $version);
            Storage::disk('local')->move($this->rutaPendiente($token), $destino);

            $vigente?->update(['estado' => HorarioPeriodo::REEMPLAZADO]);

            $periodo = HorarioPeriodo::query()->create([
                'anio' => $anio,
                'mes' => $mes,
                'version' => $version,
                'estado' => HorarioPeriodo::VIGENTE,
                'archivo_ruta' => $destino,
                'archivo_nombre' => $nombre,
                'archivo_hash' => $hash,
                'subido_por' => $user->id,
                'publicado_at' => now(),
            ]);

            $this->insertarTurnos($periodo, $lectura['filas']);
            $sinAplicar = 0;

            if ($vigente !== null) {
                $sinAplicar = $this->copiarAjustes($periodo, $vigente);
                $this->solicitudes->reaplicar($periodo, $vigente);
            }

            activity('horario')->performedOn($periodo)->causedBy($user)
                ->withProperties(['turnos' => count($lectura['filas']), 'version' => $version, 'sin_aplicar' => $sinAplicar])
                ->log('horario oficial publicado');

            return [$periodo, $sinAplicar];
        });

        Cache::forget($this->clave($token));
        [$periodo, $sinAplicar] = $resultado;

        $estacionIds = collect($lectura['filas'])->pluck('estacion_id')->filter()->unique()->values();
        Estacion::query()->whereIn('id', $estacionIds)->get()->each(fn (Estacion $e) => $this->avisos->horarioPublicado($e, $periodo));

        return ['periodo' => $periodo, 'turnos' => count($lectura['filas']), 'estaciones' => $estacionIds->count(), 'sin_aplicar' => $sinAplicar];
    }

    /**
     * Los turnos que se habían ajustado (cambios autorizados y ediciones de la Zona) se
     * copian sobre el oficial nuevo, que pasa a ser el "oficial" de referencia.
     *
     * @return int Ajustes que ya no caben en el oficial nuevo (persona o fecha inexistente).
     */
    private function copiarAjustes(HorarioPeriodo $nuevo, HorarioPeriodo $anterior): int
    {
        $perdidos = 0;

        foreach ($anterior->turnos()->where('origen', HorarioTurno::AJUSTADO)->cursor() as $viejo) {
            $turno = HorarioTurno::query()->where('periodo_id', $nuevo->id)->where('no_empleado', $viejo->no_empleado)->whereDate('fecha', $viejo->fecha->toDateString())->first();

            if ($turno === null) {
                $perdidos++;

                continue;
            }

            $turno->recordarOficial();
            $turno->fill([
                'jornada' => $viejo->jornada,
                'comida' => $viejo->comida,
                'editado_por' => $viejo->editado_por,
                'editado_at' => $viejo->editado_at,
                'nota' => $viejo->nota,
            ])->save();
        }

        return $perdidos;
    }

    /**
     * @param  list<array<string, mixed>>  $filas
     */
    private function insertarTurnos(HorarioPeriodo $periodo, array $filas): void
    {
        $empleados = Empleado::query()->whereIn('no_empleado', collect($filas)->pluck('no_empleado')->unique()->all())->pluck('id', 'no_empleado');
        $ahora = Carbon::now();

        foreach (array_chunk($filas, 500) as $lote) {
            DB::table('horario_turnos')->insert(array_map(fn (array $fila) => $fila + [
                'periodo_id' => $periodo->id,
                'empleado_id' => $empleados[$fila['no_empleado']] ?? null,
                'origen' => 'oficial',
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ], $lote));
        }
    }

    /**
     * @param  array<string, mixed>  $lectura
     * @return array<string, mixed>
     */
    private function resumen(array $lectura, string $archivo): array
    {
        $nombres = Estacion::query()->pluck('nombre', 'id');
        $porEstacion = [];

        foreach ($lectura['filas'] as $fila) {
            $nombre = $fila['estacion_id'] ? ($nombres[$fila['estacion_id']] ?? '—') : __('Sin estación reconocida');
            $porEstacion[$nombre]['turnos'] = ($porEstacion[$nombre]['turnos'] ?? 0) + 1;
            $porEstacion[$nombre]['personas'][$fila['no_empleado']] = true;
        }

        $porEstacion = array_map(fn (array $d) => ['turnos' => $d['turnos'], 'personas' => count($d['personas'])], $porEstacion);
        ksort($porEstacion);

        $vigente = HorarioPeriodo::query()->where('anio', $lectura['periodo']['anio'])->where('mes', $lectura['periodo']['mes'])
            ->where('estado', HorarioPeriodo::VIGENTE)->first();

        return [
            'archivo' => mb_substr($archivo, 0, 255),
            'anio' => $lectura['periodo']['anio'],
            'mes' => $lectura['periodo']['mes'],
            'turnos' => count($lectura['filas']),
            'por_estacion' => $porEstacion,
            'hojas_omitidas' => $lectura['omitidas'],
            'sin_estacion' => $lectura['sin_estacion'],
            'recuperadas' => $lectura['recuperadas'],
            'errores' => array_slice($lectura['errores'], 0, 20),
            'total_errores' => count($lectura['errores']),
            'reemplaza_version' => $vigente?->version,
        ];
    }

    private function rutaPendiente(string $token): string
    {
        // El token se valida como UUID en la solicitud; aquí se vuelve a exigir para no armar rutas con texto libre.
        if (! Str::isUuid($token)) {
            throw ValidationException::withMessages(['archivo' => __('Vista previa no válida.')]);
        }

        return 'horarios/pendiente/'.$token.'.xlsx';
    }

    private function clave(string $token): string
    {
        return 'horarios:importacion:'.$token;
    }
}
