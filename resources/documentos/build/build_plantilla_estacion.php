<?php

/**
 * One-off builder for resources/documentos/asistencia_estacion.docx.
 *
 * The station oficio only existed as a PDF, so this script reproduces its
 * layout with PhpWord and leaves ${placeholders} that
 * App\Services\Documentos\GeneradorOficioEstacion fills through
 * PhpOffice's TemplateProcessor. The resulting .docx is meant to be
 * opened and tweaked in Word — re-run this script only to start over:
 *
 *     php resources/documentos/build/build_plantilla_estacion.php
 */

require __DIR__.'/../../../vendor/autoload.php';

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;

const FONT = 'Noto Sans';
const GUINDA = '820000';
const BODY_PT = 8;

$phpWord = new PhpWord;
$phpWord->setDefaultFontName(FONT);
$phpWord->setDefaultFontSize(BODY_PT);
$phpWord->setDefaultParagraphStyle(['spaceBefore' => 0, 'spaceAfter' => 0, 'lineHeight' => 1.0]);

$section = $phpWord->addSection([
    'pageSizeW' => 12240, 'pageSizeH' => 15840,
    'marginLeft' => 720, 'marginRight' => 720, 'marginTop' => 700, 'marginBottom' => 700,
]);

$normal = ['name' => FONT, 'size' => BODY_PT];
$bold = ['name' => FONT, 'size' => BODY_PT, 'bold' => true];
$center = ['alignment' => Jc::CENTER];

// --- Header: slogan + logo (left) / oficio number (right) ------------------
$section->addText('“${leyenda_anio}”', $bold, ['alignment' => Jc::CENTER, 'spaceAfter' => 60]);

$header = $section->addTable(['borderSize' => 0, 'borderColor' => 'FFFFFF', 'cellMargin' => 0]);
$header->addRow();
$header->addCell(5400)->addImage(__DIR__.'/../assets/logo_tren_maya.png', ['width' => 94, 'height' => 47]);
$right = $header->addCell(5400, ['valign' => 'center']);
$right->addText('Sría. Def. Nal.', $normal, ['alignment' => Jc::END]);
$right->addText('Tjta. No. ${numero_oficio}', $normal, ['alignment' => Jc::END]);

$section->addTextBreak(1, ['size' => 6]);
$section->addText('CONTROL DE ASISTENCIA', ['name' => FONT, 'size' => 14, 'bold' => true], ['alignment' => Jc::CENTER, 'spaceAfter' => 80]);
$section->addText(
    'Para atención del C. ${destinatario_nombre}, ${destinatario_cargo}',
    ['name' => FONT, 'size' => BODY_PT, 'bold' => true, 'underline' => 'single'],
    $center,
);
$section->addText('(${fecha_oficio})', $normal, ['alignment' => Jc::CENTER, 'spaceAfter' => 100]);

$intro = $section->addTextRun(['alignment' => Jc::BOTH, 'spaceAfter' => 100]);
$intro->addText('En relación con su Memorándum No. ${memorandum_referencia}, referente a las ', $normal);
$intro->addText('directivas para el control de asistencia y permanencia', $bold);
$intro->addText(' del personal perteneciente a esta entidad Tren Maya, S.A. DE C.V., se remite control de asistencia de esta Estación a mi cargo, del ', $normal);
$intro->addText('${fecha_control}.', $bold);

// --- Helpers ----------------------------------------------------------------
$heading = static function (string $text) use ($section): void {
    $section->addText($text, ['name' => FONT, 'size' => BODY_PT, 'bold' => true, 'underline' => 'single'], ['spaceBefore' => 100, 'spaceAfter' => 50]);
};

$tableStyle = ['borderSize' => 6, 'borderColor' => '000000', 'cellMargin' => 40, 'alignment' => Jc::CENTER];

/**
 * @param  list<int>  $widths
 * @param  list<string>  $labels
 */
$headerRow = static function ($table, array $widths, array $labels, int $size = 7): void {
    $table->addRow(null, ['tblHeader' => true, 'cantSplit' => true]);
    foreach ($labels as $i => $label) {
        $table->addCell($widths[$i], ['bgColor' => GUINDA, 'valign' => 'center'])
            ->addText($label, ['name' => FONT, 'size' => $size, 'bold' => true, 'color' => 'FFFFFF'], ['alignment' => Jc::CENTER]);
    }
};

/**
 * @param  list<int>  $widths
 * @param  list<string>  $values
 * @param  list<string>  $alignments  'c' centered, 'l' left
 */
$dataRow = static function ($table, array $widths, array $values, array $alignments, int $size = BODY_PT): void {
    $table->addRow(null, ['cantSplit' => true]);
    foreach ($values as $i => $value) {
        $table->addCell($widths[$i], ['valign' => 'center'])
            ->addText($value, ['name' => FONT, 'size' => $size], ['alignment' => $alignments[$i] === 'c' ? Jc::CENTER : Jc::START]);
    }
};

// --- A. Summary ------------------------------------------------------------
$heading('A. CONTROL DE BAJAS, FALTAS, VACACIONES, INCIDENCIAS, PERMISOS Y LICENCIAS MÉDICAS');
$wA = [1400, 833, 833, 833, 833, 833, 950, 950, 833, 833, 833, 833]; // 950 for "REPORTE DE AUSENTISMO"/"VACACIONES"
$tA = $section->addTable($tableStyle);
$headerRow($tA, $wA, [
    'COORDINACIÓN/ DIRECCIÓN', 'DESCANSO', 'DÍA NO LABORABLE', 'LIC. MÉDICA', 'COMISIÓN', 'AVISO DE INCIDENCIA',
    'REPORTE DE AUSENTISMO', 'VACACIONES', 'BAJAS', 'NUEVOS INGRESOS', 'PRESENTES', 'TOTALES',
], 6);
$dataRow($tA, $wA, [
    '${coordinacion}', '${a_descanso}', '${a_dia_no_laborable}', '${a_licencia_medica}', '${a_comision}',
    '${a_aviso_incidencia}', '${a_reporte_ausentismo}', '${a_vacaciones}', '${a_bajas}', '${a_nuevos_ingresos}',
    '${a_presentes}', '${a_totales}',
], ['c', 'c', 'c', 'c', 'c', 'c', 'c', 'c', 'c', 'c', 'c', 'c'], 6);

// --- B. Incidences (kept blank, as in the example) ---------------------------
$heading('B. RELACIÓN DEL PERSONAL CIVIL CON LICENCIA MÉDICA, FALTA, VACACIONES, COMISIÓN, PERMISO O INCIDENCIA');
$wB = [600, 1000, 4200, 2400, 2600];
$tB = $section->addTable($tableStyle);
$headerRow($tB, $wB, ['NO.', 'NO. TRAB.', 'NOMBRE', 'ESTATUS', 'DÍAS']);
$dataRow($tB, $wB, ['', '', '', '', ''], ['c', 'c', 'l', 'l', 'l']);

// --- C. Full station roster ---------------------------------------------------
$heading('C. RELACIÓN DEL PERSONAL QUE SE ENCUENTRA EN CUALQUIERA DE ESTOS SUPUESTOS');
$wC = [600, 1000, 4200, 5000];
$tC = $section->addTable($tableStyle);
$headerRow($tC, $wC, ['NO.', 'NO. TRAB.', 'NOMBRE COMPLETO', 'ESTATUS']);
$dataRow($tC, $wC, ['${c_no}', '${c_trab}', '${c_nombre}', '${c_estatus}'], ['c', 'c', 'l', 'l']);

// --- D. Late arrivals (not captured by the system yet) -----------------------------
$heading('D. RELACIÓN DE RETARDOS');
$wD = [1000, 1900, 4600, 3300];
$tD = $section->addTable($tableStyle);
$headerRow($tD, $wD, ['NO.', 'NO. TRAB.', 'NOMBRE', 'HORA DE LLEGADA']);
$dataRow($tD, $wD, ['', '', '', ''], ['c', 'c', 'l', 'c']);

// --- E. Bajas -------------------------------------------------------------------
$heading('E. RELACIÓN DEL PERSONAL CON BAJA O PENDIENTE DE BAJA');
$tE = $section->addTable($tableStyle);
$headerRow($tE, $wC, ['NO.', 'NO. TRAB.', 'NOMBRE COMPLETO', 'ESTATUS']);
$dataRow($tE, $wC, ['${e_no}', '${e_trab}', '${e_nombre}', '${e_estatus}'], ['c', 'c', 'l', 'l']);

// --- Closing -------------------------------------------------------------------------
$section->addText('Respetuosamente', ['name' => FONT, 'size' => 9], ['alignment' => Jc::CENTER, 'spaceBefore' => 160]);
$section->addText('${firmante_cargo}', ['name' => FONT, 'size' => 9], ['alignment' => Jc::CENTER, 'spaceAfter' => 900]);
$section->addText('${firmante_nombre}', ['name' => FONT, 'size' => 9], ['alignment' => Jc::CENTER, 'spaceAfter' => 200]);
$section->addText('c.c.p.  ${copia_para}', ['name' => FONT, 'size' => BODY_PT], ['spaceBefore' => 120]);

$target = __DIR__.'/../asistencia_estacion.docx';
$phpWord->save($target, 'Word2007');
echo "Plantilla escrita en {$target}\n";
