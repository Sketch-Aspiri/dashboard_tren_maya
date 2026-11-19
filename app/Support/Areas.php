<?php

namespace App\Support;

use App\Models\ComisionadoFuera;
use App\Models\ComisionadoVisitante;
use App\Models\DocumentoAsistencia;
use App\Models\Empleado;
use App\Models\HorarioConsolidado;
use App\Models\HorarioPeriodo;
use App\Models\HorarioSolicitud;
use App\Models\HorarioTurno;
use App\Models\InventarioEstacion;
use App\Models\RegistroDiario;
use App\Models\SolicitudInsumo;
use App\Models\SolicitudPapeleria;

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

    /** Clave => nombre para mostrar. */
    public const NOMBRES = [
        self::RH => 'Recursos Humanos',
        self::RM => 'Recursos Materiales',
        self::MANTENIMIENTO => 'Mantenimiento',
        self::SEGURIDAD => 'Seguridad Física',
        self::FINANZAS => 'Finanzas y comercialización',
        self::ADMINISTRACION => 'Administración',
        self::SOPORTE => 'Soporte del Sistema',
        self::JEFE => 'Jefe de estación',
    ];

    /** Grupo del menú (config/navegacion.php) => área dueña. Los grupos que no figuran no son de ningún área. */
    public const GRUPOS = [
        'rrhh' => self::RH,
        'recursos-materiales' => self::RM,
        'recursos-financieros' => self::FINANZAS,
        'sgd-fis' => self::SEGURIDAD,
    ];

    /** Modelo (el que reciben las Policies) => área dueña del módulo. */
    public const MODELOS = [
        RegistroDiario::class => self::RH,
        ComisionadoFuera::class => self::RH,
        ComisionadoVisitante::class => self::RH,
        DocumentoAsistencia::class => self::RH,
        Empleado::class => self::RH,
        HorarioPeriodo::class => self::RH,
        HorarioTurno::class => self::RH,
        HorarioSolicitud::class => self::RH,
        HorarioConsolidado::class => self::RH,
        SolicitudInsumo::class => self::RM,
        SolicitudPapeleria::class => self::RM,
        InventarioEstacion::class => self::RM,
    ];

    /** @return list<string> */
    public static function claves(): array
    {
        return array_keys(self::NOMBRES);
    }

    /** Área dueña de un modelo (objeto o nombre de clase), o null si el módulo no es de un área. */
    public static function deModelo(object|string $modelo): ?string
    {
        return self::MODELOS[is_object($modelo) ? $modelo::class : $modelo] ?? null;
    }
}
