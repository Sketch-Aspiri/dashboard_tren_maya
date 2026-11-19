<?php

namespace App\Services\Vacaciones;

use App\Models\VacacionPeriodo;
use App\Models\VacacionPersona;
use App\Models\VacacionSolicitud;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Excel del rol de vacaciones vigente para Coordinación: GENERAL, una hoja por estación y CAMBIOS
 * (tramos ajustados respecto al oficial). Se genera al momento; no se guarda.
 */
final class VacacionConsolidadoLibro
{
    private const ENCABEZADO = ['No. Empleado', 'Nombre completo', 'Puesto', 'Estación', 'Días otorgados',
        '1T Inicio', '1T Término', '1T Días', '2T Inicio', '2T Término', '2T Días',
        '3T Inicio', '3T Término', '3T Días', '4T Inicio', '4T Término', '4T Días', 'Total solicitados', 'Saldo'];

    public function generar(VacacionPeriodo $periodo, string $ruta): void
    {
        $personas = VacacionPersona::query()->with(['estacion:id,nombre,orden', 'tramos'])->where('periodo_id', $periodo->id)->orderBy('orden')->get();
        $libro = new Spreadsheet;
        $general = $libro->getActiveSheet()->setTitle('GENERAL');
        $this->hoja($general, $personas);

        $porEstacion = $personas->groupBy(fn (VacacionPersona $p) => $p->estacion?->nombre ?? 'Sin estación')->sortKeys();
        $usados = ['general' => true, 'cambios' => true];

        foreach ($porEstacion as $nombre => $grupo) {
            $titulo = $this->titulo((string) $nombre, $usados);
            $this->hoja($libro->createSheet()->setTitle($titulo), $grupo);
        }

        $this->cambios($libro->createSheet()->setTitle('CAMBIOS'), $periodo);
        $libro->setActiveSheetIndex(0);

        (new Xlsx($libro))->save($ruta);
        $libro->disconnectWorksheets();
    }

    /**
     * @param  iterable<VacacionPersona>  $personas
     */
    private function hoja(Worksheet $hoja, iterable $personas): void
    {
        $hoja->fromArray(self::ENCABEZADO, null, 'A1');
        $fila = 2;

        foreach ($personas as $p) {
            $linea = [$p->no_empleado, $p->nombre, $p->puesto, $p->estacion?->nombre, $p->dias_otorgados];

            foreach ($p->tramos as $t) {
                array_push($linea, $t->inicio?->format('d/m/Y'), $t->fin?->format('d/m/Y'), $t->dias);
            }

            $total = $p->diasSolicitados();
            array_push($linea, $total, $p->dias_otorgados - $total);
            $this->escribir($hoja, $linea, $fila++);
        }

        $this->formato($hoja, count(self::ENCABEZADO));
    }

    private function cambios(Worksheet $hoja, VacacionPeriodo $periodo): void
    {
        $hoja->fromArray(['Estación', 'No. Empleado', 'Nombre', 'Trimestre', 'Oficial', 'Actual', 'Motivo / nota'], null, 'A1');
        $fila = 2;
        $personas = VacacionPersona::query()->with(['estacion:id,nombre', 'tramos'])->where('periodo_id', $periodo->id)->orderBy('orden')->get();
        $motivos = VacacionSolicitud::query()->where('periodo_id', $periodo->id)->where('estado', VacacionSolicitud::AUTORIZADA)->orderBy('resuelta_at')->get()
            ->groupBy('tramo_id')->map(fn ($g) => $g->last()->motivo);

        foreach ($personas as $p) {
            foreach ($p->tramos->where('origen', 'ajustado') as $t) {
                $rango = fn ($i, $f) => $i === null ? '—' : $i->format('d/m/Y').' al '.$f->format('d/m/Y');
                $this->escribir($hoja, [$p->estacion?->nombre, $p->no_empleado, $p->nombre, $t->trimestre,
                    $rango($t->inicio_oficial, $t->fin_oficial), $rango($t->inicio, $t->fin), $motivos[$t->id] ?? $t->nota], $fila++);
            }
        }

        $this->formato($hoja, 7);
    }

    /**
     * Texto siempre como texto: una celda que empieza con = + - @ no debe interpretarse como fórmula.
     *
     * @param  list<mixed>  $linea
     */
    private function escribir(Worksheet $hoja, array $linea, int $fila): void
    {
        foreach (array_values($linea) as $i => $valor) {
            $celda = $hoja->getCell([$i + 1, $fila]);
            is_int($valor) ? $celda->setValue($valor) : $celda->setValueExplicit((string) ($valor ?? ''));
        }
    }

    private function formato(Worksheet $hoja, int $columnas): void
    {
        $ultima = Coordinate::stringFromColumnIndex($columnas);
        $hoja->getStyle('A1:'.$ultima.'1')->getFont()->setBold(true);
        $hoja->getStyle('A1:'.$ultima.'1')->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
        $hoja->freezePane('A2');

        for ($c = 1; $c <= $columnas; $c++) {
            $hoja->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        }
    }

    /**
     * @param  array<string, true>  $usados
     */
    private function titulo(string $nombre, array &$usados): string
    {
        $base = mb_substr((string) preg_replace('/[\[\]\*\?:\\\\\/]+/u', ' ', $nombre), 0, 28) ?: 'Hoja';
        $titulo = $base;

        for ($n = 2; isset($usados[mb_strtolower($titulo)]); $n++) {
            $titulo = $base.' '.$n;
        }

        $usados[mb_strtolower($titulo)] = true;

        return $titulo;
    }
}
