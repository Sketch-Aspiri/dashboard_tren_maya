<?php

namespace App\Enums;

/**
 * Qué clase de fallo de la nube ocurrió. Lo asigna quien detecta el error
 * (ClienteNube, OAuthNubeService, proveedores) y el motor decide por el
 * código, nunca por el texto del mensaje.
 */
enum CodigoErrorNube: string
{
    /** La cuenta perdió el acceso: hay que reconectarla. */
    case Reconexion = 'reconexion';

    /** El archivo o la carpeta ya no existe o dejó de compartirse. */
    case OrigenEliminado = 'origen_eliminado';

    /** Fallo pasajero (429, 5xx, sin conexión): se reintenta. */
    case Transitorio = 'transitorio';

    /** Se rebasó un tope (tamaño, redirecciones...). */
    case Limite = 'limite';

    case Otro = 'otro';
}
