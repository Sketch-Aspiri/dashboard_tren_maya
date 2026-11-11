<?php

namespace App\Services\Ecosistema;

use App\Jobs\EnviarAvisoTickets;
use App\Models\Cotizacion;
use App\Models\Estacion;
use App\Models\SolicitudInsumo;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Avisos del Dashboard al celular por la PWA de Tickets. Cada evento decide a quién
 * avisa (por rol o estación, solo cuentas VINCULADAS) y encola un trabajo; nunca
 * rompe la acción que lo origina. Los textos son cortos y sin datos sensibles
 * (estación, mes y acción): aparecen en la pantalla bloqueada. Sin el puente
 * configurado no hace nada.
 */
final class AvisosEcosistema
{
    public function __construct(private readonly PuenteCliente $puente) {}

    /** La estación envió su solicitud → Jefe de Zona. */
    public function solicitudEnviada(SolicitudInsumo $solicitud): void
    {
        $this->enviar(
            fn () => $this->jefesDeZona(),
            __('Solicitud de insumos enviada'),
            __(':estacion envió su solicitud de :mes.', ['estacion' => $this->estacion($solicitud), 'mes' => $this->mes($solicitud)]),
            route('insumos.show', $solicitud, false),
        );
    }

    /** La Zona devolvió (observó) o aprobó la solicitud → personas de esa estación. */
    public function solicitudRevisada(SolicitudInsumo $solicitud, string $accion): void
    {
        $aprobada = $accion === 'aprobar';

        $this->enviar(
            fn () => $this->deEstacion((int) $solicitud->estacion_id),
            $aprobada ? __('Solicitud de insumos aprobada') : __('Solicitud de insumos observada'),
            $aprobada
                ? __('Tu solicitud de :mes fue aprobada.', ['mes' => $this->mes($solicitud)])
                : __('Tu solicitud de :mes tiene observaciones de Zona.', ['mes' => $this->mes($solicitud)]),
            route('insumos.estacion', $solicitud->estacion_id, false),
        );
    }

    /** Una cotización quedó con diferencias por revisar → Jefe de Zona (menos quien la capturó). */
    public function cotizacionPorRevisar(Cotizacion $cotizacion, User $capturo): void
    {
        $this->enviar(
            fn () => $this->jefesDeZona()->reject(fn (string $correo) => $correo === mb_strtolower($capturo->email))->values(),
            __('Cotización por revisar'),
            __('La cotización :folio tiene diferencias por revisar.', ['folio' => $this->limpio((string) $cotizacion->folio, 30)]),
            route('insumos.cotizaciones.show', $cotizacion, false),
        );
    }

    /** La estación aún no captura la asistencia del día → personas de esa estación. */
    public function asistenciaPendiente(Estacion $estacion): void
    {
        $this->enviar(
            fn () => $this->deEstacion((int) $estacion->id),
            __('Asistencia pendiente'),
            __(':estacion: aún falta capturar la asistencia de hoy.', ['estacion' => $this->limpio((string) $estacion->nombre, 40)]),
            route('asistencia.captura.index', [], false),
        );
    }

    /**
     * @param  \Closure(): Collection<int, string>  $destinatarios
     */
    private function enviar(\Closure $destinatarios, string $titulo, string $cuerpo, string $ruta): void
    {
        if (! $this->puente->activo()) {
            return;
        }

        try {
            $correos = $destinatarios()->unique()->values();

            foreach ($correos->chunk(50) as $lote) {
                EnviarAvisoTickets::dispatch(
                    $lote->values()->all(),
                    $this->limpio($titulo, 60),
                    $this->limpio($cuerpo, 120),
                    rtrim((string) config('ecosistema.urls.dashboard'), '/').$ruta,
                    (string) Str::uuid(),
                );
            }
        } catch (Throwable $e) {
            // Un aviso nunca debe impedir la acción que lo originó.
            Log::warning('No se pudo encolar el aviso para Tickets.', ['error' => $e::class]);
        }
    }

    /**
     * Correos (en minúsculas) de las cuentas vinculadas con ese rol.
     *
     * @return Collection<int, string>
     */
    private function jefesDeZona(): Collection
    {
        return User::role('Jefe de Zona')->whereNotNull('sso_vinculado_at')->pluck('email')
            ->map(fn (string $correo) => mb_strtolower($correo))->values();
    }

    /**
     * @return Collection<int, string>
     */
    private function deEstacion(int $estacionId): Collection
    {
        return User::role('Estación')->where('estacion_id', $estacionId)->whereNotNull('sso_vinculado_at')->pluck('email')
            ->map(fn (string $correo) => mb_strtolower($correo))->values();
    }

    private function estacion(SolicitudInsumo $solicitud): string
    {
        $solicitud->loadMissing('estacion:id,nombre');

        return $this->limpio((string) ($solicitud->estacion->nombre ?? __('Una estación')), 40);
    }

    private function mes(SolicitudInsumo $solicitud): string
    {
        return Carbon::create((int) $solicitud->anio, (int) $solicitud->mes, 1)->locale('es')->translatedFormat('F Y');
    }

    /**
     * Texto plano y corto: sin caracteres de control, recortado.
     */
    private function limpio(string $texto, int $max): string
    {
        return Str::limit(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $texto)), $max, '');
    }
}
