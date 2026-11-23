<?php

namespace App\Services\Agua;

use App\Models\AguaCompra;
use App\Models\AguaRegistro;
use App\Models\Estacion;
use App\Models\User;
use App\Services\Ecosistema\AvisosEcosistema;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Lógica de "Recursos materiales" -> Registro de agua: programa del mes por estación (lo fija la Zona y se hereda
 * mes a mes), compras con comprobante que captura la estación, envío y revisión de la Zona.
 */
final class AguaService
{
    public function __construct(private readonly AvisosEcosistema $avisos) {}

    /** El agua se registra en el mes en curso (las compras ocurren durante el mes). */
    public function mesActual(): array
    {
        $hoy = now('America/Cancun');

        return ['anio' => $hoy->year, 'mes' => $hoy->month];
    }

    /** Abre (o crea en borrador) el registro del mes heredando el programa del último mes de la estación. */
    public function obtenerOCrear(Estacion $estacion, int $anio, int $mes, User $user): AguaRegistro
    {
        $previo = AguaRegistro::query()->where('estacion_id', $estacion->id)
            ->whereRaw('(anio * 12 + mes) < ?', [$anio * 12 + $mes])->orderByDesc('anio')->orderByDesc('mes')->first();

        $registro = AguaRegistro::query()->firstOrCreate(
            ['estacion_id' => $estacion->id, 'anio' => $anio, 'mes' => $mes],
            ['estado' => AguaRegistro::BORRADOR] + ($previo?->only(['frecuencia', 'consumo_mensual', 'costo_unitario', 'garrafones_propios', 'garrafones_comodato', 'referencia', 'dia_entrega']) ?? []),
        );

        if ($registro->wasRecentlyCreated) {
            activity('agua')->performedOn($registro)->causedBy($user)->log('creado');
        }

        return $registro;
    }

    /**
     * La Zona fija el programa del mes.
     *
     * @param  array<string, mixed>  $datos  Salida validada de ActualizarProgramaAguaRequest.
     */
    public function actualizarPrograma(AguaRegistro $registro, array $datos, User $user): AguaRegistro
    {
        $registro->update($datos);
        activity('agua')->performedOn($registro)->causedBy($user)->log('programa actualizado');

        return $registro->refresh();
    }

    /**
     * @param  array{fecha: string, garrafones: int|string, nota?: string|null}  $datos
     */
    public function agregarCompra(AguaRegistro $registro, array $datos, ?UploadedFile $comprobante, User $user): AguaCompra
    {
        return DB::transaction(function () use ($registro, $datos, $comprobante, $user) {
            $registro = AguaRegistro::query()->lockForUpdate()->findOrFail($registro->id);

            if (! $registro->esEditablePorEstacion()) {
                throw ValidationException::withMessages(['compra' => __('Este registro ya se envió; no se pueden agregar compras.')]);
            }

            if ((float) $registro->costo_unitario <= 0) {
                throw ValidationException::withMessages(['compra' => __('La Zona aún no define el costo unitario de tu estación.')]);
            }

            if ($registro->compras()->count() >= (int) config('agua.max_compras')) {
                throw ValidationException::withMessages(['compra' => __('Ya registraste el máximo de :n compras del mes.', ['n' => config('agua.max_compras')])]);
            }

            $fecha = CarbonImmutable::parse($datos['fecha']);

            if ($fecha->year !== $registro->anio || $fecha->month !== $registro->mes) {
                throw ValidationException::withMessages(['fecha' => __('La fecha debe estar dentro del mes del registro.')]);
            }

            $ruta = $comprobante?->store('agua/comprobantes', 'local');
            $garrafones = (int) $datos['garrafones'];

            $compra = $registro->compras()->create([
                'fecha' => $fecha->toDateString(),
                'garrafones' => $garrafones,
                'costo_unitario' => $registro->costo_unitario,
                'monto' => round($garrafones * (float) $registro->costo_unitario, 2),
                'comprobante_ruta' => $ruta,
                'comprobante_nombre' => $comprobante === null ? null : mb_substr($comprobante->getClientOriginalName(), 0, 255),
                'nota' => $datos['nota'] ?? null,
                'origen' => AguaCompra::CAPTURA,
                'capturo_id' => $user->id,
            ]);

            return $compra;
        });
    }

    public function quitarCompra(AguaCompra $compra, User $user): void
    {
        DB::transaction(function () use ($compra, $user) {
            $registro = AguaRegistro::query()->lockForUpdate()->findOrFail($compra->registro_id);

            if (! $registro->esEditablePorEstacion()) {
                throw ValidationException::withMessages(['compra' => __('Este registro ya se envió; no se pueden quitar compras.')]);
            }

            if ($compra->comprobante_ruta !== null) {
                Storage::disk('local')->delete($compra->comprobante_ruta);
            }

            activity('agua')->performedOn($registro)->causedBy($user)->withProperties(['monto' => (string) $compra->monto])->log('compra eliminada');
            $compra->delete();
        });
    }

    public function enviar(AguaRegistro $registro, User $user): AguaRegistro
    {
        if ($registro->compras()->doesntExist()) {
            throw ValidationException::withMessages(['compra' => __('Registra al menos una compra antes de enviar.')]);
        }

        $registro->update(['estado' => AguaRegistro::ENVIADA, 'enviada_at' => now()]);
        activity('agua')->performedOn($registro)->causedBy($user)->log('enviada');
        $this->avisos->aguaEnviada($registro->loadMissing('estacion:id,nombre'), $user);

        return $registro;
    }

    /**
     * Decisión de la Zona: devolver (con comentario) o aprobar.
     *
     * @param  array{accion: string, comentario_zona?: string|null}  $datos
     */
    public function revisar(AguaRegistro $registro, array $datos, User $user): AguaRegistro
    {
        $aprobar = $datos['accion'] === 'aprobar';

        $registro->update([
            'estado' => $aprobar ? AguaRegistro::APROBADA : AguaRegistro::OBSERVADA,
            'comentario_zona' => $datos['comentario_zona'] ?? null,
            'revisada_por' => $user->id,
            'revisada_at' => now(),
        ]);

        activity('agua')->performedOn($registro)->causedBy($user)->withProperties(['accion' => $datos['accion']])->log('revisada: '.$datos['accion']);
        $this->avisos->aguaRevisada($registro);

        return $registro->refresh();
    }

    /**
     * Una fila por estación (en su orden) con el registro del mes, si existe.
     *
     * @return Collection<int, array{estacion: Estacion, registro: AguaRegistro|null}>
     */
    public function tablero(int $anio, int $mes): Collection
    {
        $registros = AguaRegistro::query()->with('compras')->where('anio', $anio)->where('mes', $mes)->get()->keyBy('estacion_id');

        return Estacion::query()->orderBy('orden')->get()
            ->map(fn (Estacion $estacion) => ['estacion' => $estacion, 'registro' => $registros->get($estacion->id)]);
    }
}
