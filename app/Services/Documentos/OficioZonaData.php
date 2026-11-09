<?php

namespace App\Services\Documentos;

use App\Models\Estacion;
use Carbon\CarbonInterface;

/**
 * Everything an attendance oficio needs. It is the same document for the
 * whole zone ($estacion null) and for a single station: personnel lists exclude anyone who is
 * commissioned out that day (they appear only in section D), so each
 * summary table always equals its own list.
 */
final readonly class OficioZonaData
{
    /**
     * @param  list<FilaPersonal>  $militares
     * @param  list<FilaPersonal>  $permanentes
     * @param  list<FilaPersonal>  $eventuales
     * @param  list<FilaComisionado>  $comisionadosOtrasCoordinaciones
     * @param  list<FilaComisionado>  $comisionadosAOtrasAreas
     * @param  list<FilaComisionado>  $eventualesAOtrasAreas
     */
    public function __construct(
        public CarbonInterface $fecha,
        public array $militares,
        public array $permanentes,
        public array $eventuales,
        public ResumenAsistencia $resumenMilitares,
        public ResumenAsistencia $resumenPermanentes,
        public ResumenAsistencia $resumenEventuales,
        public array $comisionadosOtrasCoordinaciones,
        public array $comisionadosAOtrasAreas,
        public array $eventualesAOtrasAreas,
        public ResumenComisionados $resumenComisionados,
        public ?Estacion $estacion = null,
    ) {}
}
