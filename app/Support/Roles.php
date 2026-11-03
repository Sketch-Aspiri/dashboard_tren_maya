<?php

namespace App\Support;

/**
 * Única fuente de los nombres de rol (spatie/laravel-permission). Los
 * nombres existentes nunca se renombran: la base de producción ya los usa.
 */
final class Roles
{
    public const JEFE_ZONA = 'Jefe de Zona';

    public const ADMINISTRADOR = 'Administrador';

    public const EDIFICIO = 'Edificio';

    public const ESTACION = 'Estación';

    /** Ven y editan todos los datos (y la estructura). */
    public const ZONA = [self::JEFE_ZONA, self::ADMINISTRADOR, self::EDIFICIO];

    /** Aprueban secciones para estaciones y usan el asistente. */
    public const JEFATURA = [self::JEFE_ZONA, self::ADMINISTRADOR];

    /** Roles que no se pueden combinar con ningún otro. */
    public const EXCLUSIVOS = [self::EDIFICIO, self::ESTACION];
}
