<?php

namespace App\Services\Documentos;

use App\Enums\EstatusAsistencia;
use App\Models\Estacion;
use Carbon\CarbonInterface;

/**
 * Everything the station oficio needs, with no Eloquent inside the lists.
 */
final readonly class OficioEstacionData
{
    /**
     * @param  list<FilaPersonal>  $filas
     */
    public function __construct(
        public Estacion $estacion,
        public CarbonInterface $fecha,
        public array $filas,
        public ResumenAsistencia $resumen,
    ) {}

    /**
     * Section E: personnel with status Baja.
     *
     * @return list<FilaPersonal>
     */
    public function bajas(): array
    {
        return array_values(array_filter(
            $this->filas,
            fn (FilaPersonal $fila) => $fila->estatus === EstatusAsistencia::Baja,
        ));
    }
}
