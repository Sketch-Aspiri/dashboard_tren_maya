<?php

namespace App\Services\Ecosistema;

use App\Jobs\EnviarAvisoPortal;
use App\Jobs\EnviarAvisoTickets;
use App\Models\Cotizacion;
use App\Models\Estacion;
use App\Models\HorarioConsolidado;
use App\Models\HorarioPeriodo;
use App\Models\HorarioSolicitud;
use App\Models\SolicitudInsumo;
use App\Models\User;
use App\Models\VacacionPeriodo;
use App\Models\VacacionPlan;
use App\Models\VacacionSolicitud;
use App\Notifications\AvisoDashboard;
use App\Support\Areas;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Avisos del Dashboard al celular por la PWA de Tickets. Cada evento decide a quién
 * avisa (por rol o estación), guarda la notificación en la bandeja local y encola el push a Tickets (solo cuentas VINCULADAS); nunca
 * rompe la acción que lo origina. Los textos son cortos y sin datos sensibles
 * (estación, mes y acción): aparecen en la pantalla bloqueada. Sin el puente
 * configurado no hace nada.
 */
final class AvisosEcosistema
{
    public function __construct(private readonly PuenteCliente $puente, private readonly FirmaPuente $firma) {}

    /** La estación envió su solicitud → Jefe de Zona y Edificio (que también revisa; no se avisa a quien la envió). */
    public function solicitudEnviada(SolicitudInsumo $solicitud): void
    {
        $this->enviar(
            fn () => $this->jefesDeZona()->merge($this->deEdificio())->reject(fn (User $u) => $u->id === auth()->id())->values(),
            'insumos',
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
            fn () => $this->deEstacion((int) $solicitud->estacion_id, Areas::deGrupo('recursos-materiales')),
            'insumos',
            $aprobada ? __('Solicitud de insumos aprobada') : __('Solicitud de insumos observada'),
            $aprobada
                ? __('Tu solicitud de :mes fue aprobada.', ['mes' => $this->mes($solicitud)])
                : __('Tu solicitud de :mes tiene observaciones de Zona.', ['mes' => $this->mes($solicitud)]),
            route('insumos.estacion', $solicitud->estacion_id, false),
        );
    }

    /** La Zona corrigió productos de la solicitud al revisarla (sin devolverla) → personas de esa estación. */
    public function productoModificado(SolicitudInsumo $solicitud, int $cambios): void
    {
        $this->enviar(
            fn () => $this->deEstacion((int) $solicitud->estacion_id, Areas::deGrupo('recursos-materiales')),
            'insumos',
            __('Zona modificó tu solicitud de insumos'),
            __('Zona cambió :n dato(s) de producto en tu solicitud de :mes.', ['n' => $cambios, 'mes' => $this->mes($solicitud)]),
            route('insumos.show', $solicitud, false),
        );
    }

    /** Una cotización quedó con diferencias por revisar → Jefe de Zona (menos quien la capturó). */
    public function cotizacionPorRevisar(Cotizacion $cotizacion, User $capturo): void
    {
        $this->enviar(
            fn () => $this->jefesDeZona()->reject(fn (User $u) => $u->is($capturo))->values(),
            'insumos',
            __('Cotización por revisar'),
            __('La cotización :folio tiene diferencias por revisar.', ['folio' => $this->limpio((string) $cotizacion->folio, 30)]),
            route('insumos.cotizaciones.show', $cotizacion, false),
        );
    }

    /** La estación aún no captura la asistencia del día → personas de esa estación. */
    public function asistenciaPendiente(Estacion $estacion): void
    {
        $this->enviar(
            fn () => $this->deEstacion((int) $estacion->id, Areas::deGrupo('rrhh')),
            'asistencia',
            __('Asistencia pendiente'),
            __(':estacion: aún falta capturar la asistencia de hoy.', ['estacion' => $this->limpio((string) $estacion->nombre, 40)]),
            route('asistencia.captura.index', [], false),
        );
    }

    /**
     * Hoy cumple años alguien → todas las cuentas. Solo nombres (nunca la fecha de nacimiento).
     *
     * @param  list<string>  $nombres
     */
    public function cumpleanosHoy(array $nombres): void
    {
        $nombres = array_values(array_map(fn (string $n) => $this->limpio($n, 40), $nombres));
        $total = count($nombres);

        $cuerpo = match (true) {
            $total === 1 => __('Hoy cumple años :nombre.', ['nombre' => $nombres[0]]),
            $total === 2 => __('Hoy cumplen años :a y :b.', ['a' => $nombres[0], 'b' => $nombres[1]]),
            default => __('Hoy cumplen años :a, :b y :n más.', ['a' => $nombres[0], 'b' => $nombres[1], 'n' => $total - 2]),
        };

        $this->enviar(
            fn () => User::query()->get(),
            'cumpleanos',
            $total === 1 ? __('Cumpleaños de hoy') : __('Cumpleaños de hoy (:n)', ['n' => $total]),
            $cuerpo,
            route('cumpleanos.index', [], false),
        );
    }

    /** La Zona publicó el rol de vacaciones → personas de esa estación. */
    public function vacacionesPublicadas(Estacion $estacion, VacacionPeriodo $periodo): void
    {
        $this->enviar(
            fn () => $this->deEstacion((int) $estacion->id, Areas::deGrupo('rrhh')),
            'vacaciones',
            __('Rol de vacaciones publicado'),
            __('Ya está el rol de vacaciones :anio de :estacion.', ['anio' => $periodo->anio, 'estacion' => $this->limpio((string) $estacion->nombre, 40)]),
            route('vacaciones.index', [], false),
        );
    }

    /** La estación pidió cambios a sus vacaciones → Edificio. */
    public function vacacionSolicitudNueva(VacacionSolicitud $solicitud, int $cantidad = 1): void
    {
        $this->enviar(
            fn () => $this->deEdificio()->merge($this->jefesDeZona()),
            'vacaciones',
            __('Cambio de vacaciones por autorizar'),
            __(':estacion pidió :n cambio(s) de vacaciones.', ['estacion' => $this->limpio((string) ($solicitud->estacion->nombre ?? __('Una estación')), 40), 'n' => $cantidad]),
            route('vacaciones.solicitudes.index', [], false),
        );
    }

    /** La Zona resolvió un envío de vacaciones → personas de la estación. */
    public function vacacionSolicitudResuelta(VacacionSolicitud $solicitud, int $cantidad = 1): void
    {
        $autorizada = $solicitud->estado === VacacionSolicitud::AUTORIZADA;

        $this->enviar(
            fn () => $this->deEstacion((int) $solicitud->estacion_id, Areas::deGrupo('rrhh')),
            'vacaciones',
            $autorizada ? __('Cambio de vacaciones autorizado') : __('Cambio de vacaciones rechazado'),
            $autorizada ? __('Se autorizaron :n cambio(s) de vacaciones.', ['n' => $cantidad]) : __('No se autorizaron :n cambio(s) de vacaciones.', ['n' => $cantidad]),
            route('vacaciones.solicitudes.index', [], false),
        );
    }

    /** Una persona envió su selección de vacaciones → Edificio y Jefe de Zona. */
    public function vacacionPlanEnviado(VacacionPlan $plan): void
    {
        $this->enviar(
            fn () => $this->deEdificio()->merge($this->jefesDeZona()),
            'vacaciones',
            __('Selección de vacaciones por revisar'),
            __(':estacion: una persona envió sus vacaciones :anio.', ['estacion' => $this->limpio((string) ($plan->estacion->nombre ?? __('Una estación')), 40), 'anio' => $plan->anio]),
            route('vacaciones.seleccion.revision', [], false),
        );
    }

    /** La Zona resolvió la selección de una persona → su cuenta. */
    public function vacacionPlanResuelto(VacacionPlan $plan): void
    {
        $autorizada = $plan->estado === VacacionPlan::AUTORIZADA;

        $this->enviar(
            fn () => User::query()->where('empleado_id', $plan->empleado_id)->get(),
            'vacaciones',
            $autorizada ? __('Vacaciones autorizadas') : __('Vacaciones rechazadas'),
            $autorizada ? __('Se autorizó tu selección de vacaciones :anio.', ['anio' => $plan->anio]) : __('Tu selección de vacaciones :anio necesita cambios.', ['anio' => $plan->anio]),
            route('vacaciones.mi.show', [], false),
        );
    }

    /** La Zona modificó vacaciones de la estación directamente. */
    public function vacacionesEditadas(Estacion $estacion, int $cantidad): void
    {
        $this->enviar(
            fn () => $this->deEstacion((int) $estacion->id, Areas::deGrupo('rrhh')),
            'vacaciones',
            __('Rol de vacaciones modificado'),
            __('Edificio modificó :n tramo(s) de vacaciones de tu estación.', ['n' => $cantidad]),
            route('vacaciones.index', [], false),
        );
    }

    /** Edificio subió el horario oficial → personas de esa estación. */
    public function horarioPublicado(Estacion $estacion, HorarioPeriodo $periodo): void
    {
        $this->enviar(
            fn () => $this->deEstacion((int) $estacion->id, Areas::deGrupo('rrhh')),
            'horarios',
            __('Horario publicado'),
            __('Ya está el horario de :mes para :estacion.', [
                'mes' => Carbon::create((int) $periodo->anio, (int) $periodo->mes, 1)->locale('es')->translatedFormat('F Y'),
                'estacion' => $this->limpio((string) $estacion->nombre, 40),
            ]),
            route('horarios.index', [], false),
        );
    }

    /** La estación pidió un cambio de horario → Edificio. */
    public function horarioSolicitudNueva(HorarioSolicitud $solicitud, int $cantidad = 1): void
    {
        $solicitud->loadMissing('estacion:id,nombre');

        $this->enviar(
            fn () => $this->deEdificio(),
            'horarios',
            __('Cambio de horario por autorizar'),
            $cantidad > 1
                ? __(':estacion pidió :n cambios de horario.', ['estacion' => $this->limpio((string) ($solicitud->estacion->nombre ?? __('Una estación')), 40), 'n' => $cantidad])
                : __(':estacion pidió un cambio de horario.', ['estacion' => $this->limpio((string) ($solicitud->estacion->nombre ?? __('Una estación')), 40)]),
            route('horarios.solicitudes.index', [], false),
        );
    }

    /** Edificio autorizó o rechazó el cambio → personas de esa estación. */
    public function horarioSolicitudResuelta(HorarioSolicitud $solicitud, int $cantidad = 1): void
    {
        $autorizada = $solicitud->estado === HorarioSolicitud::AUTORIZADA;

        $this->enviar(
            fn () => $this->deEstacion((int) $solicitud->estacion_id, Areas::deGrupo('rrhh')),
            'horarios',
            $autorizada ? __('Cambio de horario autorizado') : __('Cambio de horario rechazado'),
            match (true) {
                $cantidad > 1 && $autorizada => __('Se autorizaron :n cambios de horario.', ['n' => $cantidad]),
                $cantidad > 1 => __('No se autorizaron :n cambios de horario.', ['n' => $cantidad]),
                $autorizada => __('Se autorizó el cambio de horario del :fecha.', ['fecha' => $solicitud->fecha->format('d/m/Y')]),
                default => __('No se autorizó el cambio de horario del :fecha.', ['fecha' => $solicitud->fecha->format('d/m/Y')]),
            },
            route('horarios.solicitudes.index', [], false),
        );
    }

    /** La Zona modificó turnos de la estación → personas de esa estación. */
    public function horarioEditado(Estacion $estacion, int $cantidad): void
    {
        $this->enviar(
            fn () => $this->deEstacion((int) $estacion->id, Areas::deGrupo('rrhh')),
            'horarios',
            __('Horario modificado'),
            trans_choice('Edificio modificó :n turno de tu horario.|Edificio modificó :n turnos de tu horario.', $cantidad, ['n' => $cantidad]),
            route('horarios.index', [], false),
        );
    }

    /** El consolidado semanal ya está listo → Edificio. */
    public function horarioConsolidadoListo(HorarioConsolidado $consolidado): void
    {
        $this->enviar(
            fn () => $this->deEdificio(),
            'horarios',
            __('Consolidado de horarios listo'),
            __('El consolidado de la semana del :fecha ya se puede descargar.', ['fecha' => $consolidado->semana_inicio->format('d/m/Y')]),
            route('horarios.consolidados.index', [], false),
        );
    }

    /** @return Collection<int, User> */
    private function deEdificio(): Collection
    {
        return User::role('Edificio')->get();
    }

    /**
     * Guarda el aviso en la bandeja local de TODAS las cuentas destinatarias (estén o no unidas a Tickets)
     * y manda el push a la PWA de Tickets solo a las vinculadas. Cada parte falla por separado y nunca
     * rompe la acción que originó el aviso. Todo aviso nuevo debe pasar por aquí.
     *
     * @param  \Closure(): Collection<int, User>  $destinatarios
     */
    private function enviar(\Closure $destinatarios, string $modulo, string $titulo, string $cuerpo, string $ruta): void
    {
        $titulo = $this->limpio($titulo, 60);
        $cuerpo = $this->limpio($cuerpo, 120);

        try {
            $usuarios = $destinatarios()->unique('id')->values();
        } catch (Throwable $e) {
            Log::warning('No se pudieron resolver los destinatarios del aviso.', ['error' => $e::class]);

            return;
        }

        try {
            Notification::send($usuarios, new AvisoDashboard($modulo, $titulo, $cuerpo, $ruta));
        } catch (Throwable $e) {
            Log::warning('No se pudo guardar el aviso en la bandeja.', ['error' => $e::class]);
        }

        if (config('ecosistema.avisos.destino') === 'portal') {
            $this->enviarAlPortal($usuarios, $modulo, $titulo, $cuerpo, $ruta);

            return;
        }

        $this->enviarATickets($usuarios, $titulo, $cuerpo, $ruta);
    }

    /**
     * Push general por el PWA del portal. Se mandan los correos de TODOS los destinatarios (el portal descarta a quien
     * no tiene cuenta activa); sin vínculo con Tickets no hace falta.
     *
     * @param  Collection<int, User>  $usuarios
     */
    private function enviarAlPortal(Collection $usuarios, string $modulo, string $titulo, string $cuerpo, string $ruta): void
    {
        if (! $this->firma->portalConfigurado()) {
            return;
        }

        try {
            $correos = $usuarios->map(fn (User $u) => mb_strtolower($u->email))
                ->reject(fn (string $correo) => str_ends_with($correo, '.invalid'))->unique()->values();

            foreach ($correos->chunk(50) as $lote) {
                EnviarAvisoPortal::dispatch(
                    $lote->values()->all(),
                    $titulo,
                    $cuerpo,
                    rtrim((string) config('ecosistema.urls.dashboard'), '/').$ruta,
                    (string) Str::uuid(),
                    $modulo,
                );
            }
        } catch (Throwable $e) {
            // Un aviso nunca debe impedir la acción que lo originó.
            Log::warning('No se pudo encolar el aviso para el portal.', ['error' => $e::class]);
        }
    }

    /** @param  Collection<int, User>  $usuarios */
    private function enviarATickets(Collection $usuarios, string $titulo, string $cuerpo, string $ruta): void
    {
        if (! $this->puente->activo()) {
            return;
        }

        try {
            $correos = $usuarios->whereNotNull('sso_vinculado_at')
                ->map(fn (User $u) => mb_strtolower($u->email))->unique()->values();

            foreach ($correos->chunk(50) as $lote) {
                EnviarAvisoTickets::dispatch(
                    $lote->values()->all(),
                    $titulo,
                    $cuerpo,
                    rtrim((string) config('ecosistema.urls.dashboard'), '/').$ruta,
                    (string) Str::uuid(),
                );
            }
        } catch (Throwable $e) {
            // Un aviso nunca debe impedir la acción que lo originó.
            Log::warning('No se pudo encolar el aviso para Tickets.', ['error' => $e::class]);
        }
    }

    /** @return Collection<int, User> */
    private function jefesDeZona(): Collection
    {
        return User::role('Jefe de Zona')->get();
    }

    /** @return Collection<int, User> */
    private function deEstacion(int $estacionId, ?string $area = null): Collection
    {
        // Con áreas estrictas, solo las cuentas del área dueña del módulo (RM: insumos; RH: asistencia y horarios).
        $cuentas = User::role('Estación')->where('estacion_id', $estacionId)->get()
            ->filter(fn (User $u): bool => $u->puedeUsarArea($area))->values();

        // Las cuentas Edificio capturan a nombre de "Edificio Zonal Este": son su personal.
        return $estacionId === Estacion::edificioId() ? $cuentas->merge($this->deEdificio())->values() : $cuentas;
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
     * Texto plano y corto: sin caracteres de control ni de formato Unicode (dirección del texto, ancho cero,
     * separadores de línea), recortado. Tickets también los rechaza.
     */
    private function limpio(string $texto, int $max): string
    {
        return Str::limit(trim((string) preg_replace('/[\p{C}\p{Zl}\p{Zp}]+/u', ' ', $texto)), $max, '');
    }
}
