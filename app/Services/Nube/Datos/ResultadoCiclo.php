<?php

namespace App\Services\Nube\Datos;

use App\Enums\MotivoOmision;

/**
 * Acumula lo que pasó en un ciclo de sincronización (para el resumen del origen).
 */
final class ResultadoCiclo
{
    public int $altas = 0;

    public int $actualizados = 0;

    public int $archivados = 0;

    public int $restaurados = 0;

    /** @var array<string, int> */
    public array $omitidos = [];

    public ?string $error = null;

    public ?string $aviso = null;

    public function omitir(MotivoOmision $motivo, ?string $error = null): void
    {
        $this->omitidos[$motivo->value] = ($this->omitidos[$motivo->value] ?? 0) + 1;
        $this->error ??= $error;
    }
}
