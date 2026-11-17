<?php

namespace App\Services\Horarios;

use App\Models\HorarioPeriodo;
use App\Models\HorarioSolicitud;
use App\Models\HorarioTurno;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Libro semanal (lunes a domingo) para Coordinación: el horario vigente de
 * todas las estaciones con los cambios autorizados ya aplicados, una hoja por
 * estación y una hoja CAMBIOS con lo que se modificó en esa semana. Todo el
 * texto va como cadena (nunca como fórmula).
 */
final class HorarioConsolidadoLibro
{
    private const COLUMNAS = ['Estación', 'No. Empleado', 'Nombre', 'Puesto', 'Fecha', 'Día', 'Jornada', 'Comida', 'Semana', 'Origen', 'Jornada oficial'];

    /**
     * @return array{libro: Spreadsheet, turnos: int, cambios: int}
     */
    public function generar(CarbonImmutable $lunes): array
    {
        $lunes = $lunes->startOfWeek(CarbonImmutable::MONDAY);
        $domingo = $lunes->addDays(6);

        $turnos = HorarioTurno::query()
            ->with('estacion:id,nombre,codigo,orden')
            ->whereIn('periodo_id', HorarioPeriodo::query()->where('estado', HorarioPeriodo::VIGENTE)->select('id'))
            ->whereBetween('fecha', [$lunes->toDateString(), $domingo->toDateString()])
            ->orderBy('fecha')->orderBy('nombre')
            ->get()
            ->sortBy(fn (HorarioTurno $t) => sprintf('%03d|%s|%s|%s', $t->estacion?->orden ?? 999, $t->estacion?->nombre ?? '', $t->nombre, $t->fecha->toDateString()))
            ->values();

        $cambios = HorarioSolicitud::query()
            ->with(['estacion:id,nombre', 'resolvio:id,name'])
            ->where('estado', HorarioSolicitud::AUTORIZADA)->where('aplicada', true)
            ->where(fn ($q) => $q->whereBetween('fecha', [$lunes->toDateString(), $domingo->toDateString()])
                ->orWhereBetween('nueva_fecha', [$lunes->toDateString(), $domingo->toDateString()]))
            ->orderBy('estacion_id')->orderBy('fecha')
            ->get();

        $libro = new Spreadsheet;
        $general = $libro->getActiveSheet()->setTitle('GENERAL');
        $this->hojaTurnos($general, $turnos);

        foreach ($turnos->groupBy(fn (HorarioTurno $t) => $t->estacion_id ?? 0) as $grupo) {
            $estacion = $grupo->first()->estacion;
            $titulo = $estacion ? trim(($estacion->codigo ?? '').' '.$estacion->nombre) : 'SIN ESTACION';
            $this->hojaTurnos($libro->createSheet()->setTitle($this->titulo($titulo, $libro)), $grupo);
        }

        $this->hojaCambios($libro->createSheet()->setTitle('CAMBIOS'), $cambios);
        $libro->setActiveSheetIndex(0);

        return ['libro' => $libro, 'turnos' => $turnos->count(), 'cambios' => $cambios->count()];
    }

    /**
     * @param  iterable<HorarioTurno>  $turnos
     */
    private function hojaTurnos(Worksheet $hoja, iterable $turnos): void
    {
        $this->encabezado($hoja, self::COLUMNAS);
        $fila = 2;

        foreach ($turnos as $t) {
            $this->fila($hoja, $fila++, [
                $t->estacion?->nombre ?? '',
                $t->no_empleado,
                $t->nombre,
                $t->puesto ?? '',
                $t->fecha->format('d/m/Y'),
                ucfirst($t->fecha->locale('es')->translatedFormat('l')),
                $t->jornada,
                $t->comida ?? '',
                $t->semana ?? '',
                $t->ajustado() ? 'Ajustado' : 'Oficial',
                $t->ajustado() ? (string) $t->jornada_oficial : '',
            ]);
        }

        $this->anchos($hoja, [22, 13, 32, 32, 12, 12, 26, 18, 11, 11, 26]);
        $hoja->freezePane('A2');
    }

    /**
     * @param  iterable<HorarioSolicitud>  $cambios
     */
    private function hojaCambios(Worksheet $hoja, iterable $cambios): void
    {
        $this->encabezado($hoja, ['Estación', 'No. Empleado', 'Persona', 'Fecha', 'Tipo', 'Jornada original', 'Cambio', 'Motivo', 'Autorizó', 'Autorizado el']);
        $fila = 2;

        foreach ($cambios as $s) {
            $esFecha = $s->tipo === HorarioSolicitud::CAMBIO_FECHA;
            $this->fila($hoja, $fila++, [
                $s->estacion?->nombre ?? '',
                $s->no_empleado,
                $s->nombre,
                $s->fecha->format('d/m/Y'),
                $esFecha ? 'Cambio de fecha' : 'Cambio de turno',
                $s->jornada_origen,
                $esFecha ? 'Se intercambia con el '.$s->nueva_fecha->format('d/m/Y') : (string) $s->nueva_jornada,
                $s->motivo,
                $s->resolvio?->name ?? '',
                $s->resuelta_at?->setTimezone('America/Cancun')->format('d/m/Y H:i') ?? '',
            ]);
        }

        $this->anchos($hoja, [22, 13, 30, 12, 16, 26, 34, 50, 24, 17]);
        $hoja->freezePane('A2');
    }

    /**
     * @param  list<string>  $columnas
     */
    private function encabezado(Worksheet $hoja, array $columnas): void
    {
        $this->fila($hoja, 1, $columnas);
        $rango = 'A1:'.$hoja->getHighestColumn().'1';
        $hoja->getStyle($rango)->getFont()->setBold(true);
        $hoja->getStyle($rango)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E6F2EE');
        $hoja->getStyle($rango)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
    }

    /**
     * @param  list<string>  $valores
     */
    private function fila(Worksheet $hoja, int $fila, array $valores): void
    {
        foreach ($valores as $indice => $valor) {
            $hoja->setCellValueExplicit([$indice + 1, $fila], (string) $valor, DataType::TYPE_STRING);
        }
    }

    /**
     * @param  list<int>  $anchos
     */
    private function anchos(Worksheet $hoja, array $anchos): void
    {
        foreach ($anchos as $indice => $ancho) {
            $hoja->getColumnDimensionByColumn($indice + 1)->setWidth($ancho);
        }
    }

    /**
     * Título válido de hoja de Excel (máx. 31, sin : \ / ? * [ ]) y único.
     */
    private function titulo(string $texto, Spreadsheet $libro): string
    {
        $base = mb_substr(trim((string) preg_replace('/[:\\\\\/?*\[\]]/u', ' ', $texto)), 0, 28) ?: 'Hoja';
        $titulo = $base;
        $n = 2;

        while ($libro->sheetNameExists($titulo)) {
            $titulo = $base.' '.$n++;
        }

        return $titulo;
    }
}
