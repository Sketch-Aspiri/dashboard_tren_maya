<?php

namespace App\Services\Secciones;

use App\Enums\ModoImportacion;
use App\Enums\TipoColumna;

/**
 * Decisiones del usuario en la vista previa de una importación.
 */
final readonly class OpcionesImportacion
{
    public const DESTINO_NUEVA = 'nueva';

    public const DESTINO_IGNORAR = 'ignorar';

    /**
     * @param  array<int, string>  $mapeo  índice de columna del documento => clave existente | 'nueva' | 'ignorar'
     * @param  array<int, TipoColumna>  $tipos  tipo de cada columna nueva
     * @param  ?int  $llave  índice de columna del documento usada para coincidir (modo actualizar)
     */
    public function __construct(
        public int $tabla,
        public int $filaEncabezado,
        public array $mapeo,
        public array $tipos,
        public ModoImportacion $modo,
        public ?int $llave,
    ) {}

    /**
     * @param  array{tabla: int|string, fila_encabezado: int|string, mapeo: array<int|string, string>, tipos?: array<int|string, string>, modo: string, llave?: int|string|null}  $validado
     */
    public static function desdeArreglo(array $validado): self
    {
        return new self(
            tabla: (int) $validado['tabla'],
            filaEncabezado: (int) $validado['fila_encabezado'],
            mapeo: array_map('strval', $validado['mapeo']),
            tipos: array_map(fn ($tipo) => TipoColumna::from($tipo), $validado['tipos'] ?? []),
            modo: ModoImportacion::from($validado['modo']),
            llave: isset($validado['llave']) && $validado['llave'] !== '' ? (int) $validado['llave'] : null,
        );
    }
}
