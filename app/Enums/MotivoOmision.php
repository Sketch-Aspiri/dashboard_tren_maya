<?php

namespace App\Enums;

/**
 * Por qué un archivo remoto no se sincroniza. Se cuenta en el `resumen` del
 * origen para que el Administrador vea qué quedó fuera y por qué.
 */
enum MotivoOmision: string
{
    case ExtensionNoPermitida = 'extension_no_permitida';
    case Grande = 'mayor_al_limite';
    case AccesoDirectoGoogle = 'acceso_directo_google';
    case Zip = 'zip';
    case Profundidad = 'profundidad_maxima';
    case TopeArchivos = 'tope_de_archivos';
    case ContenidoInvalido = 'contenido_no_valido';
    case ErrorDescarga = 'error_de_descarga';

    public function label(): string
    {
        return match ($this) {
            self::ExtensionNoPermitida => 'Extensión no permitida',
            self::Grande => 'Pesa más del límite',
            self::AccesoDirectoGoogle => 'Acceso directo de Google',
            self::Zip => 'Archivo comprimido (zip)',
            self::Profundidad => 'Subcarpeta demasiado profunda',
            self::TopeArchivos => 'Se alcanzó el máximo de archivos',
            self::ContenidoInvalido => 'El contenido no es un documento válido',
            self::ErrorDescarga => 'No se pudo descargar',
        };
    }
}
