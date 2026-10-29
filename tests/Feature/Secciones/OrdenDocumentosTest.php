<?php

namespace Tests\Feature\Secciones;

use App\Models\Seccion;
use App\Services\Secciones\ConsultaSeccionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSecciones;
use Tests\TestCase;

/**
 * Orden y filtro por tipo de la pestaña Documentos de una sección.
 */
class OrdenDocumentosTest extends TestCase
{
    use InteractsWithSecciones, RefreshDatabase;

    /**
     * @param  array<string, array{0: string, 1?: int}>  $archivos  nombre => [extensión, tamaño]
     */
    private function seccionConDocumentos(array $archivos): Seccion
    {
        $seccion = Seccion::factory()->create();

        foreach ($archivos as $nombre => [$extension, $tamano]) {
            $seccion->archivos()->create([
                'nombre_original' => $nombre,
                'ruta' => 'x/'.$nombre,
                'mime' => 'application/octet-stream',
                'extension' => $extension,
                'tamano' => $tamano ?? 100,
            ]);
        }

        return $seccion;
    }

    /**
     * @return list<string>
     */
    private function nombres(Seccion $seccion, string $orden, ?string $tipo = null): array
    {
        return app(ConsultaSeccionService::class)
            ->documentos($seccion, null, $orden, $tipo)
            ->pluck('nombre_original')->all();
    }

    public function test_orders_names_alphabetically_ignoring_case_and_accents(): void
    {
        $seccion = $this->seccionConDocumentos([
            'zeta.pdf' => ['pdf', 1], 'Árbol.pdf' => ['pdf', 1], 'beta.pdf' => ['pdf', 1],
        ]);

        $this->assertSame(['Árbol.pdf', 'beta.pdf', 'zeta.pdf'], $this->nombres($seccion, 'nombre_asc'));
        $this->assertSame(['zeta.pdf', 'beta.pdf', 'Árbol.pdf'], $this->nombres($seccion, 'nombre_desc'));
    }

    public function test_orders_by_leading_number_with_unnumbered_last(): void
    {
        $seccion = $this->seccionConDocumentos([
            '10 Oficio.pdf' => ['pdf', 1], 'Anexo.pdf' => ['pdf', 1], '2 Reporte.pdf' => ['pdf', 1], '1.5 Nota.pdf' => ['pdf', 1],
        ]);

        $this->assertSame(['1.5 Nota.pdf', '2 Reporte.pdf', '10 Oficio.pdf', 'Anexo.pdf'], $this->nombres($seccion, 'numero_asc'));
        $this->assertSame(['10 Oficio.pdf', '2 Reporte.pdf', '1.5 Nota.pdf', 'Anexo.pdf'], $this->nombres($seccion, 'numero_desc'));
    }

    public function test_orders_by_size(): void
    {
        $seccion = $this->seccionConDocumentos([
            'a.pdf' => ['pdf', 300], 'b.pdf' => ['pdf', 100], 'c.pdf' => ['pdf', 200],
        ]);

        $this->assertSame(['a.pdf', 'c.pdf', 'b.pdf'], $this->nombres($seccion, 'tamano_desc'));
        $this->assertSame(['b.pdf', 'c.pdf', 'a.pdf'], $this->nombres($seccion, 'tamano_asc'));
    }

    public function test_filters_by_extension(): void
    {
        $seccion = $this->seccionConDocumentos(['a.pdf' => ['pdf', 1], 'b.xlsx' => ['xlsx', 1]]);

        $this->assertSame(['b.xlsx'], $this->nombres($seccion, 'recientes', 'xlsx'));
        $this->assertSame(['pdf', 'xlsx'], app(ConsultaSeccionService::class)->tiposDeDocumento($seccion));
    }

    public function test_section_page_applies_order_and_type_filter_and_rejects_invalid_values(): void
    {
        $this->comoAdministrador();
        $seccion = $this->seccionConDocumentos(['b.pdf' => ['pdf', 1], 'a.pdf' => ['pdf', 1], 'c.xlsx' => ['xlsx', 1]]);

        $this->get(route('secciones.show', [$seccion, 'tab' => 'documentos', 'doc_orden' => 'nombre_asc', 'doc_tipo' => 'pdf']))
            ->assertOk()
            ->assertSeeInOrder(['a.pdf', 'b.pdf'])
            ->assertDontSee('c.xlsx');

        $this->get(route('secciones.show', [$seccion, 'doc_orden' => 'drop table']))->assertSessionHasErrors('doc_orden');
    }
}
