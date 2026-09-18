<?php

namespace App\Services\Documentos;

/**
 * A line of the zone oficio's section D (comisionados): either someone from
 * another coordination present at one of our stations, or one of our own
 * people commissioned elsewhere. Every field is already print-ready.
 */
final readonly class FilaComisionado
{
    public function __construct(
        public string $direccion,
        public string $noEmpleado,
        public string $nombre,
        public string $estatus,
        public string $ubicacion,
    ) {}
}
