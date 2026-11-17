<?php

namespace App\Services\Horarios;

use App\Models\HorarioConsolidado;
use App\Models\User;
use App\Services\Ecosistema\AvisosEcosistema;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Genera y guarda el consolidado de una semana (lunes a domingo). Regenerarlo
 * para la misma semana reemplaza el archivo: siempre queda uno por semana.
 */
final class HorarioConsolidadoService
{
    public function __construct(
        private readonly HorarioConsolidadoLibro $libro,
        private readonly AvisosEcosistema $avisos,
    ) {}

    public function generar(CarbonImmutable $lunes, ?User $usuario = null, bool $avisar = false): HorarioConsolidado
    {
        $lunes = $lunes->startOfWeek(CarbonImmutable::MONDAY);
        ['libro' => $libro, 'turnos' => $turnos, 'cambios' => $cambios] = $this->libro->generar($lunes);

        $ruta = 'horarios/consolidados/horarios_semana_'.$lunes->toDateString().'.xlsx';
        Storage::disk('local')->makeDirectory('horarios/consolidados');
        (new Xlsx($libro))->save(Storage::disk('local')->path($ruta));
        $libro->disconnectWorksheets();

        $registro = HorarioConsolidado::query()->updateOrCreate(
            ['semana_inicio' => $lunes->toDateString()],
            [
                'ruta' => $ruta,
                'nombre' => 'HORARIOS_ZO_Semana_'.$lunes->format('d-m-Y').'.xlsx',
                'turnos' => $turnos,
                'cambios' => $cambios,
                'generado_por' => $usuario?->id,
            ],
        );

        activity('horario')->performedOn($registro)->causedBy($usuario)
            ->withProperties(['semana' => $lunes->toDateString(), 'turnos' => $turnos, 'cambios' => $cambios])
            ->log('consolidado semanal generado');

        if ($avisar) {
            $this->avisos->horarioConsolidadoListo($registro);
        }

        return $registro;
    }
}
