<?php

namespace App\Services\Documentos;

use App\Enums\EstatusAsistencia;
use PhpOffice\PhpWord\Element\Cell;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\TblWidth;
use PhpOffice\PhpWord\Style\Table as TableStyle;

/**
 * Builds the attendance oficio (.docx) exactly as the automated Excel
 * (n8n) prints it, for one station or for the whole zone: A4, letterhead and
 * footer as images, the summary tables a)–d), the lists B–E and the signature
 * block. There is no .docx template: the layout lives here so both oficios
 * stay identical. Pure file generation — authorization, folio and
 * persistence belong to AsistenciaDocumentoService.
 */
final class GeneradorOficioAsistencia
{
    private const FUENTE = 'Noto Sans';

    private const GUINDA = '820000';

    /** A4 (11906 twips) minus 0.5" margins on both sides. */
    private const ANCHO_PAGINA = 10466;

    /** Lists up to this many rows are never split between pages; longer ones keep header+2 rows and the last 2 together. */
    private const FILAS_INDIVISIBLE = 8;

    /** Height of the single blank row of an empty table (collapsed, like the Excel). */
    private const ALTO_FILA_VACIA = 280;

    /** "Keep with next" for the paragraphs of the row being written (keeps tables and the signature from being cut). */
    private bool $mantener = false;

    /** Columns of the three personnel summary tables, in print order ("|" = line break). */
    private const COLUMNAS = [
        ['DESCANSO', EstatusAsistencia::Descanso],
        ['DIA NO|LABORABLE', EstatusAsistencia::DiaNoLaborable],
        ['LICENCIAS|MEDICAS', EstatusAsistencia::LicenciaMedica],
        ['COMISION', EstatusAsistencia::Comision],
        ['AVISO DE|INCIDENCIA', EstatusAsistencia::AvisoIncidencia],
        ['REPORTE DE|AUSENTISMO', EstatusAsistencia::ReporteAusentismo],
        ['VACACIONES', EstatusAsistencia::Vacaciones],
        ['BAJAS', EstatusAsistencia::Baja],
        ['NUEVOS|INGRESOS', EstatusAsistencia::NuevoIngreso],
        ['PRESENTE', EstatusAsistencia::Presente],
    ];

    /**
     * Writes the finished .docx to $destino (an absolute path).
     */
    public function generar(OficioZonaData $data, MetadatosOficio $meta, string $destino): void
    {
        // Names, notes and destinations are user input: escape XML.
        Settings::setOutputEscapingEnabled(true);

        $word = new PhpWord;
        $word->setDefaultFontName(self::FUENTE);
        $word->setDefaultFontSize(8);
        $word->setDefaultParagraphStyle(['spaceAfter' => 0, 'spaceBefore' => 0]);

        $seccion = $word->addSection([
            'paperSize' => 'A4',
            'marginLeft' => Converter::inchToTwip(0.5),
            'marginRight' => Converter::inchToTwip(0.5),
            'marginTop' => Converter::inchToTwip(1.3),
            'marginBottom' => Converter::inchToTwip(1.05),
            'headerHeight' => Converter::inchToTwip(0.25),
            'footerHeight' => Converter::inchToTwip(0.12),
        ]);

        $this->membreteYPie($seccion);
        $this->encabezado($seccion, $data, $meta);
        $this->seccionA($seccion, $data);

        $this->titulo($seccion, 'B. RELACIÓN DEL PERSONAL MILITAR ACTIVO QUE SE ENCUENTRA EN CUALQUIERA DE ESTOS SUPUESTOS.');
        // The station sheet has no direction column in B; the zone sheet prints "COORD./DIR.".
        $this->tablaDeLista($seccion, $this->filasDePersonal($data->militares), $data->estacion === null, 'COORD./DIR.');

        $this->titulo($seccion, 'C. RELACIÓN DEL PERSONAL CIVIL PRESENTE, CON LICENCIA MEDICA, COMISION, AVISO DE INCIDENCIA, REPORTE DE AUSENTISMO, VACACIONES O BAJA.');
        $this->tablaDeLista($seccion, $this->filasDePersonal($data->permanentes), true);

        $this->titulo($seccion, 'D. RELACIÓN DEL PERSONAL EVENTUAL PRESENTE, CON LICENCIA MEDICA, COMISION, AVISO DE INCIDENCIA, REPORTE DE AUSENTISMO, VACACIONES O BAJA.');
        $this->tablaDeLista($seccion, $this->filasDePersonal($data->eventuales), true);

        $this->seccionE($seccion, $data);
        $this->cierre($seccion, $meta);

        $word->save($destino, 'Word2007');
    }

    // ---------------------------------------------------------------- page

    private function membreteYPie(Section $seccion): void
    {
        $ancho = self::ANCHO_PAGINA / 20; // points

        foreach (['membrete' => $seccion->addHeader(), 'pie' => $seccion->addFooter()] as $nombre => $zona) {
            $ruta = resource_path('documentos/assets/'.$nombre.'.png');
            [$w, $h] = getimagesize($ruta);

            $zona->addImage($ruta, ['width' => $ancho, 'height' => round($ancho * $h / $w, 1), 'alignment' => Jc::CENTER]);
        }
    }

    private function encabezado(Section $seccion, OficioZonaData $data, MetadatosOficio $meta): void
    {
        $formato = config('asistencia_documentos.formato');
        $esEstacion = $data->estacion !== null;
        $fecha = FormatoFechaOficio::corta($data->fecha);

        $ambito = $esEstacion
            ? 'de la '.($data->estacion->is_operativa ? 'Estación ' : '').$data->estacion->nombre
            : 'de la '.config('asistencia_documentos.zona.sujeto');

        $seccion->addText('Tjta. No. '.$meta->numeroOficio, ['size' => 7.5], ['alignment' => Jc::END, 'spaceAfter' => 200, 'keepNext' => true]);
        $seccion->addText($formato['titulo'], ['size' => 10, 'bold' => true], ['alignment' => Jc::CENTER, 'spaceAfter' => 200, 'keepNext' => true]);
        $seccion->addText($formato['destinatario'], ['size' => 9, 'bold' => true, 'underline' => 'single'], ['alignment' => Jc::CENTER, 'spaceAfter' => 80, 'keepNext' => true]);
        $seccion->addText($fecha, ['size' => 9], ['alignment' => Jc::CENTER, 'spaceAfter' => 80, 'keepNext' => true]);
        $seccion->addText(
            sprintf(
                'En relación al Memorándum No. %s, referente a las Directivas para el control de asistencia del personal perteneciente a la Empresa Tren Maya, S.A. de C.V., me permito %s a esa superioridad, el control de asistencia diaria %s del %s.',
                $formato['memorandum'],
                $esEstacion ? 'remitir' : 'informar',
                $ambito,
                $fecha,
            ),
            ['size' => 8.5],
            ['alignment' => Jc::BOTH, 'spaceAfter' => 160, 'keepNext' => true],
        );
    }

    // ---------------------------------------------------------- section A

    private function seccionA(Section $seccion, OficioZonaData $data): void
    {
        $this->titulo($seccion, 'A.   CONTROL DE LICENCIAS MEDICAS, COMISIONES, INCIDENCIAS, AUSENTISMOS, VACACIONES, BAJA Y NUEVOS INGRESOS.');
        $this->mantener = true;

        $this->subtitulo($seccion, 'a)   TOTAL, MILITARES EN ACTIVO:');
        $this->resumen($seccion, $data->resumenMilitares, 'TOTAL MIL.ACT.');
        $this->subtitulo($seccion, 'b)   TOTAL, PLAZAS PERMANENTES:');
        $this->resumen($seccion, $data->resumenPermanentes, 'TOTAL PERMANENTES');
        $this->subtitulo($seccion, 'c)   TOTAL, PLAZAS EVENTUALES:');
        $this->resumen($seccion, $data->resumenEventuales, 'TOTAL EVENTUAL');
        $this->subtitulo($seccion, 'd)   TOTAL, PLAZAS PERMANENTES, EVENTUALES Y DE OTRAS COORDINACIONES COMISIONADOS');
        $this->resumenComisionados($seccion, $data->resumenComisionados);
        $this->mantener = false;

        $this->espacio($seccion, 10);
    }

    private function resumen(Section $seccion, ResumenAsistencia $resumen, string $etiquetaTotal): void
    {
        $tabla = $this->tabla($seccion);
        $anchos = [820, 900, 900, 820, 900, 1000, 1000, 700, 820, 800, 806];

        $tabla->addRow(null, ['cantSplit' => true]);
        foreach (self::COLUMNAS as $i => [$titulo]) {
            $this->celdaTitulo($tabla, $anchos[$i], $titulo, 5.5);
        }
        $this->celdaTitulo($tabla, $anchos[10], $etiquetaTotal, 5.5);

        $tabla->addRow(null, ['cantSplit' => true]);
        foreach (self::COLUMNAS as $i => [, $estatus]) {
            $this->celdaDato($tabla, $anchos[$i], (string) $resumen->de($estatus));
        }
        $this->celdaDato($tabla, $anchos[10], (string) $resumen->total());
    }

    private function resumenComisionados(Section $seccion, ResumenComisionados $r): void
    {
        $tabla = $this->tabla($seccion);
        // DESCANSO, DIA NO LAB., LIC., COMISION (permanente, eventual, otra coord.), AVISO, REPORTE, VACAC., BAJAS, NUEVOS, PRESENTE, TOTAL
        $anchos = [760, 760, 780, 900, 700, 700, 800, 900, 880, 600, 760, 760, 1166];
        $titulos = [
            0 => 'DESCANSO', 1 => 'DIA NO|LABORABLE', 2 => 'LICENCIAS|MEDICAS',
            6 => 'AVISO DE|INCIDENCIA', 7 => 'REPORTE DE|AUSENTISMO', 8 => 'VACACIONES',
            9 => 'BAJAS', 10 => 'NUEVOS|INGRESOS', 11 => 'PRESENTE', 12 => 'TOTAL|COMISION',
        ];
        $subtitulosComision = [3 => 'PERMANENTE', 4 => 'EVENTUAL', 5 => 'OTRA COORD.'];

        $tabla->addRow(null, ['cantSplit' => true]);
        foreach ($anchos as $i => $ancho) {
            if ($i === 3) {
                $this->celdaTitulo($tabla, $anchos[3] + $anchos[4] + $anchos[5], 'COMISION', 5.5, ['gridSpan' => 3]);
            } elseif (! isset($subtitulosComision[$i])) {
                $this->celdaTitulo($tabla, $ancho, $titulos[$i], 5.5, ['vMerge' => 'restart']);
            }
        }

        $tabla->addRow(null, ['cantSplit' => true]);
        foreach ($anchos as $i => $ancho) {
            if (isset($subtitulosComision[$i])) {
                $this->celdaTitulo($tabla, $ancho, $subtitulosComision[$i], 5);
            } else {
                $this->celdaTitulo($tabla, $ancho, '', 5.5, ['vMerge' => 'continue']);
            }
        }

        $valores = [
            $r->de(EstatusAsistencia::Descanso), $r->de(EstatusAsistencia::DiaNoLaborable), $r->de(EstatusAsistencia::LicenciaMedica),
            $r->comisionPermanente, $r->comisionEventual, $r->comisionOtraCoordinacion,
            $r->de(EstatusAsistencia::AvisoIncidencia), $r->de(EstatusAsistencia::ReporteAusentismo), $r->de(EstatusAsistencia::Vacaciones),
            $r->de(EstatusAsistencia::Baja), $r->de(EstatusAsistencia::NuevoIngreso), $r->de(EstatusAsistencia::Presente), $r->total,
        ];

        $this->mantener = false;
        $tabla->addRow(null, ['cantSplit' => true]);
        foreach ($anchos as $i => $ancho) {
            $this->celdaDato($tabla, $ancho, (string) $valores[$i]);
        }
    }

    // ------------------------------------------------------ sections B–E

    private function seccionE(Section $seccion, OficioZonaData $data): void
    {
        $this->titulo($seccion, 'E. RELACIÓN DEL PERSONAL COMISIONADO PRESENTE, CON LICENCIA MEDICA, COMISION, AVISO DE INCIDENCIA, REPORTE DE AUSENTISMO, VACACIONES O BAJA.');

        $grupos = [
            ['a.   PERSONAL COMISIONADO DE OTRAS COORDINACIONES:', $data->comisionadosOtrasCoordinaciones],
            ['b.   PERSONAL COMISIONADO A OTRAS ÁREAS:', $data->comisionadosAOtrasAreas],
            ['c.   PERSONAL EVENTUAL COMISIONADO A OTRAS ÁREAS:', $data->eventualesAOtrasAreas],
        ];

        foreach ($grupos as $indice => [$subtitulo, $filas]) {
            $this->subtitulo($seccion, $subtitulo, true);
            $this->tablaDeLista($seccion, array_map(fn (FilaComisionado $f) => [
                'dir' => $f->direccion, 'trab' => $f->noEmpleado, 'nombre' => $f->nombre,
                'estatus' => $f->estatus, 'ubic' => $f->ubicacion, 'negrita' => true, 'estatusNegrita' => true,
            ], $filas), true, 'DIRECCIÓN', true, $indice === count($grupos) - 1);
        }
    }

    /**
     * @param  list<FilaPersonal>  $filas
     * @return list<array<string, mixed>>
     */
    private function filasDePersonal(array $filas): array
    {
        $direccion = mb_strtoupper((string) config('asistencia_documentos.zona.direccion'));

        return array_map(fn (FilaPersonal $f) => [
            'dir' => $direccion,
            'trab' => $f->noEmpleado,
            'nombre' => mb_strtoupper($f->nombre),
            'estatus' => implode("\n", $f->estatusLineas()),
            'ubic' => mb_strtoupper($f->ubicacion),
            'negrita' => $f->destacada,
            'estatusNegrita' => $f->destacada || $f->estatus !== EstatusAsistencia::Presente,
        ], $filas);
    }

    /**
     * @param  list<array<string, mixed>>  $filas
     */
    private function tablaDeLista(Section $seccion, array $filas, bool $conDireccion, string $etiquetaDireccion = 'DIRECCIÓN', bool $compacta = false, bool $hastaCierre = false): void
    {
        $tabla = $this->tabla($seccion);
        $tamTitulo = $compacta ? 5.5 : 6.5;

        $anchos = $conDireccion
            ? ['no' => 420, 'dir' => 1500, 'trab' => 700, 'nombre' => 3050, 'estatus' => 2350, 'ubic' => 2446]
            : ['no' => 420, 'trab' => 1100, 'nombre' => 3300, 'estatus' => 2800, 'ubic' => 2846];
        $titulos = ['no' => 'NO.', 'dir' => $etiquetaDireccion, 'trab' => 'NO. TRAB.', 'nombre' => 'NOMBRE', 'estatus' => 'ESTATUS', 'ubic' => 'UBICACIÓN ACTUAL'];

        $this->mantener = true;
        $tabla->addRow(null, ['tblHeader' => true, 'cantSplit' => true]);
        foreach ($anchos as $clave => $ancho) {
            $this->celdaTitulo($tabla, $ancho, $titulos[$clave], $tamTitulo);
        }

        // An empty list keeps one blank row so the table does not disappear.
        $vacia = $filas === [];
        $filas = $vacia ? [['dir' => '', 'trab' => '', 'nombre' => '', 'estatus' => '', 'ubic' => '', 'negrita' => false, 'estatusNegrita' => false]] : array_values($filas);
        $alto = $vacia ? self::ALTO_FILA_VACIA : ($compacta ? 430 : 520);
        $total = count($filas);

        foreach ($filas as $indice => $fila) {
            // Small tables stay whole; long ones keep header + 2 rows and the last 2 rows together
            // (never a header alone nor a single orphan row); the last table sticks to the signature.
            $this->mantener = $total <= self::FILAS_INDIVISIBLE
                ? ($indice < $total - 1 || $hastaCierre)
                : ($indice <= 1 || $indice === $total - 2 || ($indice === $total - 1 && $hastaCierre));
            $tabla->addRow($alto, ['cantSplit' => true]);

            $this->celdaTexto($tabla, $anchos['no'], $vacia ? '' : (string) ($indice + 1), Jc::CENTER, 7, false);
            if ($conDireccion) {
                $this->celdaTexto($tabla, $anchos['dir'], $fila['dir'], Jc::CENTER, 5.5, true);
            }
            $this->celdaTexto($tabla, $anchos['trab'], $fila['trab'], Jc::CENTER, 7, false);
            $this->celdaTexto($tabla, $anchos['nombre'], $fila['nombre'], Jc::START, 7, $fila['negrita']);
            $this->celdaTexto($tabla, $anchos['estatus'], $fila['estatus'], Jc::CENTER, 7, $fila['estatusNegrita']);
            $this->celdaTexto($tabla, $anchos['ubic'], $fila['ubic'], Jc::START, 7, $fila['negrita']);
        }

        $this->mantener = $hastaCierre;
        $this->espacio($seccion, $compacta ? 3 : 8);
        $this->mantener = false;
    }

    // ---------------------------------------------------------- closing

    private function cierre(Section $seccion, MetadatosOficio $meta): void
    {
        if ($meta->textoSuplencia !== '') {
            $seccion->addText($meta->textoSuplencia, ['size' => 7], ['alignment' => Jc::BOTH, 'spaceBefore' => 200, 'spaceAfter' => 100, 'keepNext' => true]);
        }

        $centrado = ['alignment' => Jc::CENTER, 'keepNext' => true];
        $seccion->addText((string) config('asistencia_documentos.formato.despedida'), ['size' => 9], $centrado + ['spaceBefore' => 300]);
        $seccion->addText($meta->firmanteCargo, ['size' => 9], $centrado);

        $this->firma($seccion, $meta->firmaPath);

        $seccion->addText($meta->firmanteNombre, ['size' => 9], ['alignment' => Jc::CENTER, 'keepNext' => $meta->iniciales !== '']);

        if ($meta->iniciales !== '') {
            $seccion->addText($meta->iniciales, ['size' => 6.5], ['spaceBefore' => 500]);
        }
    }

    /** Fixed signature space; the image (if any) is scaled to fit it and centered. */
    private function firma(Section $seccion, ?string $ruta): void
    {
        $maxAncho = 150;
        $maxAlto = 52;
        $medidas = ($ruta !== null && is_file($ruta)) ? @getimagesize($ruta) : false;

        if ($medidas === false) {
            $seccion->addText('', ['size' => 9], ['keepNext' => true, 'spaceBefore' => 600]);

            return;
        }

        $escala = min($maxAncho / $medidas[0], $maxAlto / $medidas[1]);
        $seccion->addImage($ruta, [
            'width' => round($medidas[0] * $escala, 1),
            'height' => round($medidas[1] * $escala, 1),
            'alignment' => Jc::CENTER,
        ]);
    }

    // ----------------------------------------------------------- helpers

    private function titulo(Section $seccion, string $texto): void
    {
        $seccion->addText($texto, ['size' => 7.5, 'bold' => true, 'underline' => 'single'], ['spaceBefore' => 60, 'spaceAfter' => 100, 'keepNext' => true]);
    }

    private function subtitulo(Section $seccion, string $texto, bool $negrita = false): void
    {
        $seccion->addText($texto, ['size' => 7, 'bold' => $negrita], ['spaceBefore' => 40, 'spaceAfter' => 20, 'keepNext' => true, 'indentation' => ['left' => 100]]);
    }

    /** A thin empty paragraph used as vertical space between blocks. */
    private function espacio(Section $seccion, int $puntos): void
    {
        $seccion->addText('', ['size' => $puntos], ['keepNext' => $this->mantener]);
    }

    private function tabla(Section $seccion): Table
    {
        return $seccion->addTable([
            'borderSize' => 4,
            'borderColor' => '000000',
            'cellMargin' => 30,
            'layout' => TableStyle::LAYOUT_FIXED,
            'unit' => TblWidth::TWIP,
            'width' => self::ANCHO_PAGINA,
            'alignment' => Jc::CENTER,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function celdaTitulo(Table $tabla, int $ancho, string $texto, float $tam, array $extra = []): void
    {
        $celda = $tabla->addCell($ancho, $extra + ['bgColor' => self::GUINDA, 'valign' => 'center']);
        $this->lineas($celda, $texto, ['size' => $tam, 'bold' => true, 'color' => 'FFFFFF'], Jc::CENTER, '|');
    }

    private function celdaDato(Table $tabla, int $ancho, string $texto): void
    {
        $celda = $tabla->addCell($ancho, ['valign' => 'center']);
        $celda->addText($texto, ['size' => 7, 'bold' => true], ['alignment' => Jc::CENTER, 'spaceBefore' => 20, 'spaceAfter' => 20, 'keepNext' => $this->mantener]);
    }

    private function celdaTexto(Table $tabla, int $ancho, string $texto, string $alineacion, float $tam, bool $negrita): void
    {
        $celda = $tabla->addCell($ancho, ['valign' => 'center']);
        $this->lineas($celda, $texto, ['size' => $tam, 'bold' => $negrita], $alineacion, "\n");
    }

    /**
     * One paragraph per line, so a multi-line text prints on separate lines.
     *
     * @param  array<string, mixed>  $fuente
     */
    private function lineas(Cell $celda, string $texto, array $fuente, string $alineacion, string $separador): void
    {
        foreach (explode($separador, $texto) as $linea) {
            $celda->addText($linea, $fuente, ['alignment' => $alineacion, 'spaceBefore' => 10, 'spaceAfter' => 10, 'keepNext' => $this->mantener]);
        }
    }
}
