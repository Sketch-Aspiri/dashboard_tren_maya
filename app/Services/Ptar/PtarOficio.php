<?php

namespace App\Services\Ptar;

use App\Models\PtarHallazgo;
use App\Models\PtarPlanta;
use App\Services\Documentos\FormatoFechaOficio;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Style\ListItem;

/**
 * Oficio mensual de estatus de las PTAR (.docx): una sección por planta con sus problemáticas y propuestas abiertas,
 * seguida de las consideraciones generales y la firma. Carta, Noto Sans 11, texto justificado, como el oficio que la
 * Zona armaba a mano. Solo genera el archivo: la autorización y la descarga son del controlador.
 */
final class PtarOficio
{
    private const FUENTE = 'Noto Sans';

    private const TAMANO = 11;

    /**
     * Secciones del oficio: una por planta con hallazgos abiertos, en el orden del catálogo. Las problemáticas son
     * el texto de los hallazgos de tipo problemática; las propuestas, la propuesta de cada problemática más los
     * hallazgos de tipo propuesta.
     *
     * @return Collection<int, array{estacion: string, problematicas: list<string>, propuestas: list<string>}>
     */
    public function secciones(): Collection
    {
        return PtarPlanta::query()->with(['estacion', 'hallazgos' => fn ($q) => $q->where('estado', '!=', PtarHallazgo::CERRADO)->orderBy('id')])
            ->where('activa', true)->get()
            ->sortBy(fn (PtarPlanta $p) => $p->numero ?? PHP_INT_MAX)
            ->map(function (PtarPlanta $planta) {
                $problematicas = $planta->hallazgos->where('tipo', PtarHallazgo::PROBLEMATICA);
                $propuestas = $problematicas->pluck('propuesta')->merge($planta->hallazgos->where('tipo', PtarHallazgo::PROPUESTA)->pluck('descripcion'));

                return [
                    'estacion' => $planta->estacion->nombre,
                    'problematicas' => $problematicas->pluck('descripcion')->map(fn ($t) => trim((string) $t))->filter()->values()->all(),
                    'propuestas' => $propuestas->map(fn ($t) => trim((string) $t))->filter()->values()->all(),
                ];
            })
            ->filter(fn (array $s) => $s['problematicas'] !== [] || $s['propuestas'] !== [])
            ->values();
    }

    /**
     * @param  array{leyenda: string, numero: string, fecha: CarbonInterface, destinatario: string, asunto: string, consideraciones: list<string>, firmante_cargo: string, firmante_nombre: string}  $datos
     * @param  Collection<int, array{estacion: string, problematicas: list<string>, propuestas: list<string>}>  $secciones
     */
    public function generar(array $datos, Collection $secciones, string $destino): void
    {
        Settings::setOutputEscapingEnabled(true);

        $word = new PhpWord;
        $word->setDefaultFontName(self::FUENTE);
        $word->setDefaultFontSize(self::TAMANO);
        $word->setDefaultParagraphStyle(['spaceAfter' => 120, 'spaceBefore' => 0, 'alignment' => Jc::BOTH]);

        $seccion = $word->addSection([
            'paperSize' => 'Letter', 'marginTop' => 851, 'marginBottom' => 851, 'marginLeft' => 1134, 'marginRight' => 1134,
        ]);

        $this->encabezado($seccion, $datos);
        $this->titulo($seccion, '1. ASUNTO');
        $seccion->addText($datos['asunto']);
        $this->titulo($seccion, '2. INFORMACIÓN');

        foreach ($secciones->values() as $i => $s) {
            $this->planta($seccion, chr(ord('a') + $i % 26).') '.rtrim($s['estacion'], '.').'.', $s);
        }

        $this->titulo($seccion, '3. CONSIDERACIÓN');
        foreach ($datos['consideraciones'] as $parrafo) {
            $seccion->addText($parrafo);
        }

        $this->firma($seccion, $datos);

        IOFactory::createWriter($word, 'Word2007')->save($destino);
    }

    /** @param  array<string, mixed>  $datos */
    private function encabezado(Section $seccion, array $datos): void
    {
        $centro = ['alignment' => Jc::CENTER];
        $seccion->addText($datos['leyenda'], ['bold' => true], $centro);
        $seccion->addText('Tren Maya, S.A. de C.V', null, ['alignment' => Jc::LEFT, 'spaceAfter' => 0]);
        $seccion->addText('Coord. Gral. de Gest. de Infra. Ferrov.', null, ['alignment' => Jc::LEFT, 'spaceAfter' => 0]);
        $seccion->addText('No. '.$datos['numero'], null, ['alignment' => Jc::RIGHT]);
        $seccion->addText('Para atención del '.$datos['destinatario'], ['bold' => true, 'underline' => 'single'], $centro);
        $seccion->addText('('.FormatoFechaOficio::corta($datos['fecha']).')', null, $centro);
    }

    private function titulo(Section $seccion, string $texto): void
    {
        $seccion->addText($texto, ['bold' => true, 'underline' => 'single'], ['spaceBefore' => 120, 'keepNext' => true]);
    }

    /** @param  array{estacion: string, problematicas: list<string>, propuestas: list<string>}  $s */
    private function planta(Section $seccion, string $nombre, array $s): void
    {
        $seccion->addText($nombre, ['bold' => true], ['keepNext' => true, 'spaceBefore' => 120]);

        $this->lista($seccion, count($s['problematicas']) === 1 ? 'Problemática.' : 'Problemáticas.', $s['problematicas']);
        $this->lista($seccion, count($s['propuestas']) === 1 ? 'Propuesta.' : 'Propuestas.', $s['propuestas']);
    }

    /** @param  list<string>  $items */
    private function lista(Section $seccion, string $rotulo, array $items): void
    {
        if ($items === []) {
            return;
        }

        $seccion->addText($rotulo, null, ['indentation' => ['left' => 360], 'keepNext' => true, 'spaceAfter' => 60]);
        foreach ($items as $item) {
            $seccion->addListItem($item, 0, null, ['listType' => ListItem::TYPE_BULLET_FILLED], ['spaceAfter' => 60]);
        }
    }

    /** @param  array<string, mixed>  $datos */
    private function firma(Section $seccion, array $datos): void
    {
        $centro = ['alignment' => Jc::CENTER, 'keepNext' => true, 'spaceAfter' => 0];
        $seccion->addTextBreak(2);
        $seccion->addText('Respetuosamente', null, $centro);
        $seccion->addText($datos['firmante_cargo'], null, $centro);
        $seccion->addTextBreak(3);
        $seccion->addText($datos['firmante_nombre'], ['bold' => true], ['alignment' => Jc::CENTER]);
    }
}
