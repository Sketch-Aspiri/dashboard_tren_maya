<?php

namespace App\Support;

use App\Models\ComisionadoFuera;
use App\Models\ComisionadoVisitante;
use App\Models\DocumentoAsistencia;
use App\Models\Empleado;
use App\Models\Equipo;
use App\Models\HorarioConsolidado;
use App\Models\HorarioPeriodo;
use App\Models\HorarioSolicitud;
use App\Models\HorarioTurno;
use App\Models\InventarioEstacion;
use App\Models\RegistroDiario;
use App\Models\SolicitudInsumo;
use App\Models\SolicitudPapeleria;
use App\Models\VacacionPeriodo;
use App\Models\VacacionSolicitud;
use App\Models\VacacionTramo;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Áreas (equipos) de una cuenta de estación. Las claves son las mismas en el Portal y en Tickets: no se renombran.
 * Una cuenta Estación pertenece a UNA estación y a UN área, y solo ve los módulos de esa área.
 */
final class Areas
{
    public const RH = 'rh';

    public const RM = 'rm';

    public const MANTENIMIENTO = 'mantenimiento';

    public const SEGURIDAD = 'seguridad';

    public const FINANZAS = 'finanzas';

    public const ADMINISTRACION = 'administracion';

    public const SOPORTE = 'soporte';

    /** No es un equipo: el jefe de estación accede a todos los equipos de SU estación (como la cuenta Estación de antes). */
    public const JEFE = 'jefe';

    public const NOMBRE_JEFE = 'Jefe de estación';

    /** Equipos originales (clave => nombre): lo que carga la migración y el respaldo si la tabla no existe. */
    public const NOMBRES_BASE = [
        self::RH => 'Recursos Humanos',
        self::RM => 'Recursos Materiales',
        self::MANTENIMIENTO => 'Mantenimiento',
        self::SEGURIDAD => 'Seguridad Física',
        self::FINANZAS => 'Finanzas y comercialización',
        self::ADMINISTRACION => 'Administración',
        self::SOPORTE => 'Soporte del Sistema',
    ];

    /** Grupos originales del menú => equipo dueño. */
    public const GRUPOS_BASE = [
        'rrhh' => self::RH,
        'recursos-materiales' => self::RM,
        'recursos-financieros' => self::FINANZAS,
        'sgd-fis' => self::SEGURIDAD,
    ];

    /** Modelo (el que reciben las Policies) => grupo del menú al que pertenece el módulo (el equipo dueño sale de la base). */
    public const MODELOS = [
        RegistroDiario::class => 'rrhh',
        ComisionadoFuera::class => 'rrhh',
        ComisionadoVisitante::class => 'rrhh',
        DocumentoAsistencia::class => 'rrhh',
        Empleado::class => 'rrhh',
        HorarioPeriodo::class => 'rrhh',
        HorarioTurno::class => 'rrhh',
        HorarioSolicitud::class => 'rrhh',
        HorarioConsolidado::class => 'rrhh',
        VacacionPeriodo::class => 'rrhh',
        VacacionTramo::class => 'rrhh',
        VacacionSolicitud::class => 'rrhh',
        SolicitudInsumo::class => 'recursos-materiales',
        SolicitudPapeleria::class => 'recursos-materiales',
        InventarioEstacion::class => 'recursos-materiales',
    ];

    /**
     * Mapa vigente (de la base, en caché): nombres por clave y equipo dueño de cada grupo del menú. Sin la tabla
     * (migración pendiente) o vacía cae a los valores originales.
     *
     * @return array{nombres: array<string, string>, grupos: array<string, string>}
     */
    private static function mapa(): array
    {
        return Cache::rememberForever(Equipo::CACHE, function (): array {
            try {
                $equipos = Equipo::query()->with('menuGrupos:id,slug')->orderBy('id')->get();
            } catch (Throwable) {
                $equipos = collect();
            }

            if ($equipos->isEmpty()) {
                return ['nombres' => self::NOMBRES_BASE, 'grupos' => self::GRUPOS_BASE];
            }

            $grupos = [];
            foreach ($equipos as $equipo) {
                foreach ($equipo->menuGrupos as $grupo) {
                    $grupos[$grupo->slug] = $equipo->clave;
                }
            }

            return ['nombres' => $equipos->pluck('nombre', 'clave')->all(), 'grupos' => $grupos];
        });
    }

    /** Equipos vigentes más el «Jefe de estación» (clave => nombre). */
    public static function nombres(): array
    {
        return self::mapa()['nombres'] + [self::JEFE => self::NOMBRE_JEFE];
    }

    /** @return list<string> */
    public static function claves(): array
    {
        return array_keys(self::nombres());
    }

    /** Equipo dueño de un grupo del menú, o null si el grupo no es de ningún equipo. */
    public static function deGrupo(string $slug): ?string
    {
        return self::mapa()['grupos'][$slug] ?? null;
    }

    /** Equipo dueño de un modelo (objeto o nombre de clase), o null si el módulo no es de un equipo. */
    public static function deModelo(object|string $modelo): ?string
    {
        $grupo = self::MODELOS[is_object($modelo) ? $modelo::class : $modelo] ?? null;

        return $grupo === null ? null : self::deGrupo($grupo);
    }
}
