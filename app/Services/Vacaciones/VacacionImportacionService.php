<?php

namespace App\Services\Vacaciones;

use App\Models\Empleado;
use App\Models\Estacion;
use App\Models\User;
use App\Models\VacacionPeriodo;
use App\Models\VacacionPersona;
use App\Models\VacacionSolicitud;
use App\Models\VacacionTramo;
use App\Services\Ecosistema\AvisosEcosistema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sube el rol de vacaciones: vista previa (nada se guarda en las tablas) y luego aplicar, que lo
 * publica y avisa a cada estación. Resubir el mismo año crea una versión nueva, conserva los
 * tramos ya ajustados y cancela las solicitudes que seguían pendientes.
 */
final class VacacionImportacionService
{
    private const TTL_MINUTOS = 60;

    public function __construct(
        private readonly VacacionLector $lector,
        private readonly AvisosEcosistema $avisos,
    ) {}

    /**
     * @return array{token: string, resumen: array<string, mixed>}
     */
    public function previsualizar(UploadedFile $archivo, User $user): array
    {
        $token = (string) Str::uuid();
        $ruta = $this->rutaPendiente($token);
        Storage::disk('local')->putFileAs('vacaciones/pendiente', $archivo, $token.'.xlsx');

        $lectura = $this->lector->leer(Storage::disk('local')->path($ruta));

        if ($lectura['personas'] === [] || $lectura['anio'] === null) {
            Storage::disk('local')->delete($ruta);

            throw ValidationException::withMessages([
                'archivo' => $lectura['errores'][0] ?? __('No se encontraron personas con vacaciones. Revisa que las hojas traigan No. Empleado, Nombre completo, Días otorgados y los trimestres.'),
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
     * @return array{periodo: VacacionPeriodo, personas: int, estaciones: int, sin_aplicar: int}
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
        $nombre = (string) ($dato['resumen']['archivo'] ?? 'vacaciones.xlsx');
        $anio = (int) $lectura['anio'];

        $vigente = VacacionPeriodo::query()->where('anio', $anio)->where('estado', VacacionPeriodo::VIGENTE)->first();

        if ($vigente !== null && $vigente->archivo_hash === $hash) {
            throw ValidationException::withMessages(['archivo' => __('Este archivo es idéntico al rol vigente de ese año.')]);
        }

        [$periodo, $sinAplicar] = DB::transaction(function () use ($lectura, $vigente, $anio, $hash, $nombre, $token, $user) {
            $version = (int) VacacionPeriodo::query()->where('anio', $anio)->max('version') + 1;
            $destino = sprintf('vacaciones/oficiales/%d-v%d.xlsx', $anio, $version);
            Storage::disk('local')->move($this->rutaPendiente($token), $destino);

            $vigente?->update(['estado' => VacacionPeriodo::REEMPLAZADO]);

            $periodo = VacacionPeriodo::query()->create([
                'anio' => $anio, 'version' => $version, 'estado' => VacacionPeriodo::VIGENTE, 'archivo_ruta' => $destino,
                'archivo_nombre' => $nombre, 'archivo_hash' => $hash, 'subido_por' => $user->id, 'publicado_at' => now(),
            ]);

            $this->insertar($periodo, $lectura['personas']);
            $sinAplicar = $vigente === null ? 0 : $this->copiarAjustes($periodo, $vigente);

            activity('vacaciones')->performedOn($periodo)->causedBy($user)
                ->withProperties(['personas' => count($lectura['personas']), 'version' => $version, 'sin_aplicar' => $sinAplicar])
                ->log('rol de vacaciones publicado');

            return [$periodo, $sinAplicar];
        });

        Cache::forget($this->clave($token));

        $estacionIds = collect($lectura['personas'])->pluck('estacion_id')->filter()->unique()->values();
        Estacion::query()->whereIn('id', $estacionIds)->get()->each(fn (Estacion $e) => $this->avisos->vacacionesPublicadas($e, $periodo));

        return ['periodo' => $periodo, 'personas' => count($lectura['personas']), 'estaciones' => $estacionIds->count(), 'sin_aplicar' => $sinAplicar];
    }

    /**
     * Copia los tramos ajustados de la versión anterior sobre la nueva y cancela las solicitudes pendientes.
     *
     * @return int Ajustes que ya no caben (la persona dejó de estar en el rol).
     */
    private function copiarAjustes(VacacionPeriodo $nuevo, VacacionPeriodo $anterior): int
    {
        $perdidos = 0;

        foreach (VacacionTramo::query()->where('periodo_id', $anterior->id)->where('origen', VacacionTramo::AJUSTADO)->with('persona:id,no_empleado')->cursor() as $viejo) {
            $persona = VacacionPersona::query()->where('periodo_id', $nuevo->id)->where('no_empleado', $viejo->persona->no_empleado)->first();
            $tramo = $persona?->tramos()->where('trimestre', $viejo->trimestre)->first();

            if ($tramo === null) {
                $perdidos++;

                continue;
            }

            $tramo->recordarOficial();
            $tramo->fill([
                'inicio' => $viejo->inicio, 'fin' => $viejo->fin, 'dias' => $viejo->dias,
                'editado_por' => $viejo->editado_por, 'editado_at' => $viejo->editado_at, 'nota' => $viejo->nota,
            ])->save();
        }

        VacacionSolicitud::query()->where('periodo_id', $anterior->id)->where('estado', VacacionSolicitud::PENDIENTE)
            ->update(['estado' => VacacionSolicitud::CANCELADA, 'comentario_resolucion' => __('Se publicó una versión nueva del rol.')]);

        return $perdidos;
    }

    /**
     * @param  list<array<string, mixed>>  $personas
     */
    private function insertar(VacacionPeriodo $periodo, array $personas): void
    {
        $empleados = Empleado::query()->whereIn('no_empleado', collect($personas)->pluck('no_empleado')->all())->pluck('id', 'no_empleado');

        foreach ($personas as $orden => $p) {
            $persona = VacacionPersona::query()->create([
                'periodo_id' => $periodo->id, 'estacion_id' => $p['estacion_id'], 'no_empleado' => $p['no_empleado'],
                'empleado_id' => $empleados[$p['no_empleado']] ?? null, 'nombre' => $p['nombre'], 'puesto' => $p['puesto'],
                'dias_otorgados' => $p['dias_otorgados'], 'orden' => $orden,
            ]);

            foreach ($p['tramos'] as $trimestre => $t) {
                VacacionTramo::query()->create([
                    'periodo_id' => $periodo->id, 'persona_id' => $persona->id, 'estacion_id' => $p['estacion_id'],
                    'trimestre' => $trimestre, 'inicio' => $t['inicio'], 'fin' => $t['fin'], 'dias' => $t['dias'], 'origen' => VacacionTramo::OFICIAL,
                ]);
            }
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
        $excedidos = 0;

        foreach ($lectura['personas'] as $p) {
            $nombre = $p['estacion_id'] ? ($nombres[$p['estacion_id']] ?? '—') : __('Sin estación reconocida');
            $porEstacion[$nombre]['personas'] = ($porEstacion[$nombre]['personas'] ?? 0) + 1;
            $porEstacion[$nombre]['dias'] = ($porEstacion[$nombre]['dias'] ?? 0) + array_sum(array_column($p['tramos'], 'dias'));

            if (array_sum(array_column($p['tramos'], 'dias')) > $p['dias_otorgados']) {
                $excedidos++;
            }
        }

        ksort($porEstacion);
        $vigente = VacacionPeriodo::query()->where('anio', $lectura['anio'])->where('estado', VacacionPeriodo::VIGENTE)->first();

        return [
            'archivo' => mb_substr($archivo, 0, 255),
            'anio' => $lectura['anio'],
            'personas' => count($lectura['personas']),
            'por_estacion' => $porEstacion,
            'hojas_omitidas' => $lectura['omitidas'],
            'sin_estacion' => $lectura['sin_estacion'],
            'discrepancias' => $lectura['discrepancias'],
            'excedidos' => $excedidos,
            'errores' => array_slice($lectura['errores'], 0, 20),
            'total_errores' => count($lectura['errores']),
            'reemplaza_version' => $vigente?->version,
        ];
    }

    private function rutaPendiente(string $token): string
    {
        if (! Str::isUuid($token)) {
            throw ValidationException::withMessages(['archivo' => __('Vista previa no válida.')]);
        }

        return 'vacaciones/pendiente/'.$token.'.xlsx';
    }

    private function clave(string $token): string
    {
        return 'vacaciones:importacion:'.$token;
    }
}
