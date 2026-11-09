<?php

namespace App\Services\Documentos;

/**
 * The free-form, per-generation values of an oficio: its folio, who signs,
 * the closing initials and the digital signature image (absolute path to a
 * temporary PNG, or null to leave the signature space blank). The suplencia
 * text only applies to the zone oficio.
 */
final readonly class MetadatosOficio
{
    public function __construct(
        public string $numeroOficio,
        public string $firmanteCargo,
        public string $firmanteNombre,
        public string $iniciales = '',
        public string $textoSuplencia = '',
        public ?string $firmaPath = null,
    ) {}

    public function conFirma(?string $firmaPath): self
    {
        return new self(
            $this->numeroOficio,
            $this->firmanteCargo,
            $this->firmanteNombre,
            $this->iniciales,
            $this->textoSuplencia,
            $firmaPath,
        );
    }
}
