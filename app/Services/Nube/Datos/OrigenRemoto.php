<?php

namespace App\Services\Nube\Datos;

use App\Enums\MotivoTruncado;

/**
 * Lo que un enlace de la nube resulta ser: un archivo o una carpeta, con sus
 * identificadores remotos y los totales que se mostrarán al confirmar.
 */
final readonly class OrigenRemoto
{
    public const ARCHIVO = 'archivo';

    public const CARPETA = 'carpeta';

    /**
     * @param  'archivo'|'carpeta'  $tipo
     * @param  array<string, int>  $omitidos  motivo (MotivoOmision) => cantidad
     */
    public function __construct(
        public string $tipo,
        public ?string $driveId,
        public string $itemId,
        public string $nombre,
        public int $archivos = 1,
        public array $omitidos = [],
        public int $excel = 0,
        public ?MotivoTruncado $truncado = null,
    ) {}

    public function esCarpeta(): bool
    {
        return $this->tipo === self::CARPETA;
    }
}
