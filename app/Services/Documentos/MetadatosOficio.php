<?php

namespace App\Services\Documentos;

/**
 * The free-form, per-generation values of an oficio: its folio and who
 * signs. Supplied (and editable) by the person who generates the document.
 * The last two only apply to the zone oficio.
 */
final readonly class MetadatosOficio
{
    public function __construct(
        public string $numeroOficio,
        public string $firmanteCargo,
        public string $firmanteNombre,
        public string $iniciales = '',
        public string $textoSuplencia = '',
    ) {}
}
