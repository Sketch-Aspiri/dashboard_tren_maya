<?php

namespace App\Services\Ptar;

use App\Models\Estacion;
use App\Models\PtarEquipo;
use App\Models\PtarHallazgo;
use App\Models\PtarPlanta;
use App\Models\PtarRegistro;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Excel mensual de las PTAR con la misma estructura del control original: un bloque de 5 filas por planta (modo de
 * operación, norma, equipos con estatus, actividades con su última fecha y observaciones), más una hoja con los
 * hallazgos sin cerrar. Las fotos no se incrustan (el Excel crecería decenas de MB); se indica cuántas hay.
 */
final class PtarLibro
{
    private const MESES = [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'];

    private const COLORES = ['verde' => 'C6EFCE', 'ambar' => 'FFEB9C', 'rojo' => 'FFC7CE', 'gris' => 'EDEDED'];

    private const TEXTO_SEMAFORO = ['verde' => 'Operación normal', 'ambar' => 'Con atención', 'rojo' => 'Con fallas', 'gris' => 'Sin dato'];

    /**
     * @param  Collection<int, array{planta: PtarPlanta, estacion: Estacion, registro: PtarRegistro|null, semaforo: string, vencidas: int, hallazgos_abiertos: int}>  $tablero
     */
    public function mes(int $anio, int $mes, Collection $tablero): Spreadsheet
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle(self::MESES[$mes].' '.$anio);

        $hoja->setCellValue('A1', 'PLANTA DE TRATAMIENTO DE AGUAS RESIDUALES (PTAR) — '.mb_strtoupper(self::MESES[$mes]).' '.$anio);
        $hoja->mergeCells('A1:O1');
        $hoja->getStyle('A1')->getFont()->setBold(true)->setSize(13);

        $cabeceras = ['No.', 'Tramo', 'Estación', 'Modo de operación', 'Detalle del modo', 'Norma', 'Equipo eléctrico', 'Estatus', 'Observaciones del equipo', 'Actividad', 'Última fecha de mantenimiento', 'Observaciones generales', 'Fotos', 'Estado del registro', 'Situación'];
        foreach ($cabeceras as $i => $texto) {
            $hoja->setCellValue([$i + 1, 3], $texto);
        }
        $this->encabezado($hoja, 'A3:O3');

        $fila = 4;
        foreach ($tablero as $f) {
            $fila = $this->bloque($hoja, $fila, $f);
        }

        foreach (['A' => 6, 'B' => 8, 'C' => 24, 'D' => 18, 'E' => 28, 'F' => 26, 'G' => 18, 'H' => 18, 'I' => 40, 'J' => 16, 'K' => 18, 'L' => 60, 'M' => 8, 'N' => 14, 'O' => 18] as $col => $ancho) {
            $hoja->getColumnDimension($col)->setWidth($ancho);
        }
        $hoja->freezePane('D4');

        $this->hojaHallazgos($libro);

        $libro->setActiveSheetIndex(0);

        return $libro;
    }

    /**
     * @param  array{planta: PtarPlanta, estacion: Estacion, registro: PtarRegistro|null, semaforo: string, vencidas: int, hallazgos_abiertos: int}  $f
     */
    private function bloque(Worksheet $hoja, int $inicio, array $f): int
    {
        $r = $f['registro'];
        $fin = $inicio + 4;
        $equipos = $r?->equipos->keyBy('equipo');
        $actividades = $r?->actividades->values() ?? collect();

        $hoja->setCellValue('A'.$inicio, $f['planta']->numero);
        $this->texto($hoja, 'B'.$inicio, (string) $f['planta']->tramo);
        $this->texto($hoja, 'C'.$inicio, $f['estacion']->nombre);
        $this->texto($hoja, 'D'.$inicio, $r?->etiquetaModo() ?? 'Sin captura');
        $this->texto($hoja, 'E'.$inicio, (string) $r?->modo_detalle);
        $this->texto($hoja, 'F'.$inicio, implode("\n", $f['planta']->normas ?? []));
        $this->texto($hoja, 'I'.$inicio, (string) $r?->observaciones_equipos);
        $this->texto($hoja, 'L'.$inicio, (string) $r?->observaciones_generales);
        $hoja->setCellValue('M'.$inicio, $r?->evidencias->count() ?? 0);
        $this->texto($hoja, 'N'.$inicio, $r?->etiquetaEstado() ?? 'Sin captura');
        $this->texto($hoja, 'O'.$inicio, self::TEXTO_SEMAFORO[$f['semaforo']]);

        foreach (array_keys(config('ptar.equipos')) as $i => $clave) {
            $this->texto($hoja, 'G'.($inicio + $i), config('ptar.equipos.'.$clave));
            $this->texto($hoja, 'H'.($inicio + $i), $equipos?->get($clave)?->etiquetaEstatus() ?? PtarEquipo::ETIQUETAS[PtarEquipo::SIN_DATO]);
        }

        foreach ($actividades->take(5) as $i => $actividad) {
            $this->texto($hoja, 'J'.($inicio + $i), $actividad->actividad);
            $this->texto($hoja, 'K'.($inicio + $i), trim(($actividad->ultima_fecha?->format('d/m/Y') ?? '').($actividad->nota ? ($actividad->ultima_fecha ? ' — ' : '').$actividad->nota : '')));
        }

        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'I', 'L', 'M', 'N', 'O'] as $col) {
            $hoja->mergeCells($col.$inicio.':'.$col.$fin);
        }

        $rango = 'A'.$inicio.':O'.$fin;
        $hoja->getStyle($rango)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $hoja->getStyle($rango)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $hoja->getStyle('O'.$inicio.':O'.$fin)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLORES[$f['semaforo']]);
        // El texto largo vive en celdas combinadas de 5 filas: reparte la altura necesaria entre ellas.
        $lineas = array_sum(array_map(fn (string $p) => max(1, (int) ceil(mb_strlen($p) / 75)), explode('
', (string) $r?->observaciones_generales)));
        $alto = min(60, max(18, $lineas * 13 / 5));
        for ($i = $inicio; $i <= $fin; $i++) {
            $hoja->getRowDimension($i)->setRowHeight($alto);
        }

        return $fin + 1;
    }

    private function hojaHallazgos(Spreadsheet $libro): void
    {
        $hoja = $libro->createSheet();
        $hoja->setTitle('Hallazgos abiertos');

        foreach (['Estación', 'Tipo', 'Equipo', 'Prioridad', 'Estado', 'Descripción', 'Propuesta', 'Detectado', 'Compromiso', 'Tarjeta'] as $i => $texto) {
            $hoja->setCellValue([$i + 1, 1], $texto);
        }
        $this->encabezado($hoja, 'A1:J1');

        $hallazgos = PtarHallazgo::query()->with('planta.estacion')->where('estado', '!=', PtarHallazgo::CERRADO)
            ->orderBy('planta_id')->orderBy('id')->get();

        foreach ($hallazgos as $i => $h) {
            $fila = $i + 2;
            $valores = [
                $h->planta->estacion->nombre, $h->tipo === PtarHallazgo::PROPUESTA ? 'Propuesta' : 'Problemática',
                config('ptar.equipos.'.$h->equipo, ''), ucfirst($h->prioridad), $h->estado === PtarHallazgo::EN_PROCESO ? 'En proceso' : 'Abierto',
                $h->descripcion, (string) $h->propuesta, $h->fecha_detectado?->format('d/m/Y') ?? '', $h->fecha_compromiso?->format('d/m/Y') ?? '', (string) $h->tarjeta,
            ];
            foreach ($valores as $c => $valor) {
                $hoja->setCellValueExplicit([$c + 1, $fila], (string) $valor, DataType::TYPE_STRING);
            }
        }

        foreach (['A' => 24, 'B' => 14, 'C' => 18, 'D' => 10, 'E' => 12, 'F' => 60, 'G' => 50, 'H' => 12, 'I' => 12, 'J' => 22] as $col => $ancho) {
            $hoja->getColumnDimension($col)->setWidth($ancho);
        }
        $hoja->getStyle('A2:J'.max(2, $hallazgos->count() + 1))->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $hoja->freezePane('A2');
    }

    /** Texto de usuario siempre como cadena: una celda que empiece con "=" se evaluaría como fórmula al abrir el Excel. */
    private function texto(Worksheet $hoja, string $celda, mixed $valor): void
    {
        $hoja->setCellValueExplicit($celda, (string) $valor, DataType::TYPE_STRING);
    }

    private function encabezado(Worksheet $hoja, string $rango): void
    {
        $hoja->getStyle($rango)->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9E1F2']],
            'alignment' => ['wrapText' => true, 'horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
    }
}
