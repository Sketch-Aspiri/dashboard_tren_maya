<?php

namespace App\Services\Nube;

use App\Models\NubeCuenta;
use App\Services\Secciones\NubeSyncException;

/**
 * Decide qué hacer ante un error de la nube. ClienteNube solo lanza
 * NubeSyncException con un mensaje, así que se reconoce por esos mismos
 * textos (deben mantenerse iguales a los de ClienteNube::mensajeError) y por
 * el estado de la cuenta.
 */
final class ClasificadorErrorNube
{
    public const RECONEXION = 'reconexion';

    public const ELIMINADO = 'eliminado';

    public const TRANSITORIO = 'transitorio';

    public const PERMANENTE = 'permanente';

    public function clasificar(NubeSyncException $e, ?NubeCuenta $cuenta): string
    {
        $mensaje = $e->getMessage();

        return match (true) {
            ($cuenta !== null && ! $cuenta->fresh()?->estaActiva())
                || $mensaje === __('La nube rechazó el acceso de la cuenta. Reconéctala desde Conexiones de nube.') => self::RECONEXION,
            $mensaje === __('No se encontró el elemento en la nube. Revisa que siga compartido con la cuenta conectada.') => self::ELIMINADO,
            $mensaje === __('La nube limitó las peticiones. Se reintentará en la próxima revisión.'),
            $mensaje === __('No se pudo conectar con la nube. Se reintentará en la próxima revisión.'),
            preg_match('/error 5\d\d\.?$/', $mensaje) === 1 => self::TRANSITORIO,
            default => self::PERMANENTE,
        };
    }
}
