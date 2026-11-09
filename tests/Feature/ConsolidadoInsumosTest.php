<?php

namespace Tests\Feature;

use App\Models\Estacion;
use App\Models\SolicitudInsumo;
use App\Models\SolicitudInsumoPartida;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Feature coverage for the monthly Excel consolidado (ANEXO, LISTADO POR
 * PRODUCTO, CONTROL_FILTRO). Dummy factory data only
 * (.claude/rules/testing.md).
 */
class ConsolidadoInsumosTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private Estacion $conAprobada;

    private Estacion $conBorrador;

    private Estacion $sinSolicitud;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        // Orden esperado en el ANEXO: 23, 24 y al final el 00.
        $this->sinSolicitud = Estacion::factory()->create(['nombre' => 'Edificio Zonal Este', 'codigo' => '00', 'orden' => 1]);
        $this->conBorrador = Estacion::factory()->create(['nombre' => 'Playa del Carmen', 'codigo' => '24', 'orden' => 2]);
        $this->conAprobada = Estacion::factory()->create(['nombre' => 'Puerto Morelos', 'codigo' => '23', 'orden' => 3]);
    }

    private function userWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    private function aprobada(Estacion $estacion, array $partidas): SolicitudInsumo
    {
        $solicitud = SolicitudInsumo::factory()->create([
            'estacion_id' => $estacion->id, 'anio' => 2026, 'mes' => 11, 'estado' => SolicitudInsumo::APROBADA,
            'solicitante_nombre' => 'Lic. Prueba', 'solicitante_puesto' => 'Gerencia de Estación',
        ]);

        foreach ($partidas as $indice => $datos) {
            SolicitudInsumoPartida::factory()->create($datos + ['solicitud_insumo_id' => $solicitud->id, 'numero' => $indice + 1]);
        }

        return $solicitud;
    }

    /**
     * @return array<string, mixed>
     */
    private function consulta(string $tipo, array $extra = []): array
    {
        return ['anio' => 2026, 'mes' => 11, 'tipo' => $tipo] + $extra;
    }

    private function libro(TestResponse $respuesta): Spreadsheet
    {
        $ruta = tempnam(sys_get_temp_dir(), 'cons').'.xlsx';
        file_put_contents($ruta, $respuesta->streamedContent());

        return IOFactory::load($ruta);
    }

    private function escenario(): void
    {
        $this->aprobada($this->conAprobada, [
            ['cantidad' => 10, 'unidad' => 'Pieza', 'descripcion' => 'Cable primario', 'precio_unitario' => 100, 'partida_presupuestal' => '24901', 'proveedor' => 'Truper', 'justificacion' => 'Reposición'],
            ['cantidad' => 1, 'unidad' => 'Pieza', 'descripcion' => '=HYPERLINK("http://x.test","clic")', 'precio_unitario' => 50],
        ]);
        SolicitudInsumo::factory()->create(['estacion_id' => $this->conBorrador->id, 'anio' => 2026, 'mes' => 11, 'estado' => SolicitudInsumo::ENVIADA]);
    }

    // --- Authentication / authorization ------------------------------------

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('insumos.consolidado', $this->consulta('anexo')))->assertRedirect(route('login'));
    }

    public function test_only_jefatura_can_download(): void
    {
        $estacion = Estacion::factory()->create();

        foreach (['Estación', 'Edificio'] as $rol) {
            $this->actingAsTwoFactorVerified($this->userWithRole($rol, ['estacion_id' => $rol === 'Estación' ? $estacion->id : null]));

            $this->get(route('insumos.consolidado', $this->consulta('anexo')))->assertForbidden();
        }

        foreach (['Jefe de Zona', 'Administrador'] as $rol) {
            $this->actingAsTwoFactorVerified($this->userWithRole($rol));

            $this->get(route('insumos.consolidado', $this->consulta('anexo')))->assertOk();
        }
    }

    public function test_validation_rejects_unknown_type_bad_month_and_unsafe_oficio(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->get(route('insumos.consolidado', $this->consulta('pdf')))->assertInvalid('tipo');
        $this->get(route('insumos.consolidado', ['anio' => 2026, 'mes' => 13, 'tipo' => 'anexo']))->assertInvalid('mes');
        $this->get(route('insumos.consolidado', $this->consulta('anexo', ['oficio' => '../../etc'])))->assertInvalid('oficio');
        $this->get(route('insumos.consolidado', $this->consulta('anexo', ['oficio' => 'a b'])))->assertInvalid('oficio');
    }

    // --- ANEXO ---------------------------------------------------------------

    public function test_anexo_has_the_expected_name_blocks_totals_and_only_approved_partidas(): void
    {
        $this->escenario();
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $respuesta = $this->get(route('insumos.consolidado', $this->consulta('anexo', ['oficio' => '1918'])))->assertOk();

        $this->assertStringContainsString('ZO_TJT_NÚM_1918_', rawurldecode($respuesta->headers->get('Content-Disposition')) ?: '');
        $this->assertStringContainsString('_NOV.xlsx', rawurldecode($respuesta->headers->get('Content-Disposition')));

        $hoja = $this->libro($respuesta)->getSheetByName('ZO_Cotización');
        $this->assertNotNull($hoja);
        $this->assertSame('INSUMOS REQUERIDOS DEL MES: NOVIEMBRE', $hoja->getCell('E5')->getValue());
        $this->assertEquals(50000, $hoja->getCell('K2')->getValue());

        // Bloque 1: 23 Puerto Morelos (aprobada).
        $this->assertSame('23_PUERTO MORELOS', $hoja->getCell('B7')->getValue());
        $this->assertSame('Lic. Prueba, Gerencia de Estación', $hoja->getCell('B8')->getValue());
        $this->assertSame('Cable primario', $hoja->getCell('E10')->getValue());
        $this->assertEquals(100, $hoja->getCell('F10')->getValue());
        $this->assertSame('=C10*F10', $hoja->getCell('G10')->getValue());
        $this->assertSame('24901', $hoja->getCell('H10')->getValue());
        $this->assertSame('Truper', $hoja->getCell('K10')->getValue());
        $this->assertSame('=SUM(G10:G11)', $hoja->getCell('G12')->getValue());
        $this->assertSame('=8%*G12', $hoja->getCell('G13')->getValue());

        // Bloque 2: 24 Playa (solo enviada) y bloque 3: 00 (sin solicitud).
        $this->assertSame('24_PLAYA DEL CARMEN', $hoja->getCell('B16')->getValue());
        $this->assertStringContainsString('pendiente de aprobación', $hoja->getCell('E19')->getValue());
        $this->assertSame('00_EDIFICIO ZONAL ESTE', $hoja->getCell('B24')->getValue());
        $this->assertSame('No realizaron solicitud a la fecha', $hoja->getCell('E27')->getValue());

        $this->assertSame('=SUM(G14,G22,G30)', $hoja->getCell('K3')->getValue());
        $this->assertSame('=K2-K3', $hoja->getCell('K4')->getValue());
    }

    public function test_user_text_starting_with_equals_is_stored_as_text_not_formula(): void
    {
        $this->escenario();
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $celda = $this->libro($this->get(route('insumos.consolidado', $this->consulta('anexo'))))
            ->getSheetByName('ZO_Cotización')->getCell('E11');

        $this->assertSame('=HYPERLINK("http://x.test","clic")', $celda->getValue());
        $this->assertSame('s', $celda->getDataType());
    }

    public function test_download_is_logged(): void
    {
        $this->actingAsTwoFactorVerified($usuario = $this->userWithRole('Jefe de Zona'));

        $this->get(route('insumos.consolidado', $this->consulta('control')))->assertOk();

        $this->assertTrue(Activity::query()->where('log_name', 'solicitud_insumo')->where('description', 'consolidado exportado')->where('causer_id', $usuario->id)->exists());
    }

    // --- LISTADO POR PRODUCTO -------------------------------------------------

    public function test_listado_groups_the_same_insumo_across_estaciones(): void
    {
        $this->aprobada($this->conAprobada, [['cantidad' => 10, 'unidad' => 'Pieza', 'descripcion' => 'Cable primario', 'precio_unitario' => 100]]);
        $this->aprobada($this->sinSolicitud, [['cantidad' => 5, 'unidad' => 'Pieza', 'descripcion' => 'cable primario', 'precio_unitario' => 130]]);
        $this->actingAsTwoFactorVerified($this->userWithRole('Administrador'));

        $respuesta = $this->get(route('insumos.consolidado', $this->consulta('listado')))->assertOk();
        $this->assertStringContainsString('SOLICITUD LISTADO POR PRODUCTO_NOV_', $respuesta->headers->get('Content-Disposition'));

        $hoja = $this->libro($respuesta)->getSheet(0);
        $this->assertSame('Insumo homologado', $hoja->getCell('B8')->getValue());
        $this->assertSame('Cable primario', $hoja->getCell('B9')->getValue());
        $this->assertEquals(15, $hoja->getCell('C9')->getValue());
        $this->assertStringContainsString('Puerto Morelos', $hoja->getCell('E9')->getValue());
        $this->assertStringContainsString('Edificio Zonal Este', $hoja->getCell('E9')->getValue());
        $this->assertEquals(1650, $hoja->getCell('G9')->getValue());          // 10*100 + 5*130
        $this->assertEquals(110, $hoja->getCell('F9')->getValue());            // promedio ponderado
        $this->assertStringContainsString('PRECIOS_DISTINTOS', $hoja->getCell('J9')->getValue());
        $this->assertSame('=SUM(G9:G9)', $hoja->getCell('G10')->getValue());
        $this->assertNull($hoja->getCell('B10')->getValue());
    }

    // --- CONTROL_FILTRO --------------------------------------------------------

    public function test_control_lists_alerts_for_missing_pending_and_incomplete_partidas(): void
    {
        $this->escenario();
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $respuesta = $this->get(route('insumos.consolidado', $this->consulta('control')))->assertOk();
        $libro = $this->libro($respuesta);

        $resumen = $libro->getSheetByName('Resumen');
        $this->assertSame('Solicitudes aprobadas (incluidas)', $resumen->getCell('A3')->getValue());
        $this->assertEquals(1, $resumen->getCell('B3')->getValue());

        $alertas = [];
        foreach ($libro->getSheetByName('Alertas')->toArray(null, true, true, true) as $i => $fila) {
            if ($i > 1) {
                $alertas[$fila['A'].'|'.$fila['B']] = $fila['F'];
            }
        }

        $this->assertSame('PENDIENTE_APROBACION', $alertas['Playa del Carmen|(solicitud enviada)']);
        $this->assertSame('SIN_SOLICITUD', $alertas['Edificio Zonal Este|(sin solicitud)']);
        $this->assertStringContainsString('SIN_PARTIDA_PRESUPUESTAL', $alertas['Puerto Morelos|=HYPERLINK("http://x.test","clic")']);
        $this->assertStringContainsString('SIN_PROVEEDOR', $alertas['Puerto Morelos|=HYPERLINK("http://x.test","clic")']);
        $this->assertArrayNotHasKey('Puerto Morelos|Cable primario', $alertas); // completa: sin alertas
    }

    public function test_control_flags_a_price_deviating_from_history(): void
    {
        $historica = $this->aprobada($this->conAprobada, [['cantidad' => 1, 'descripcion' => 'Pintura', 'precio_unitario' => 100, 'partida_presupuestal' => '24901', 'proveedor' => 'X']]);
        $historica->update(['mes' => 10]);
        $this->aprobada($this->conBorrador, [['cantidad' => 1, 'descripcion' => 'Pintura', 'precio_unitario' => 200, 'partida_presupuestal' => '24901', 'proveedor' => 'X']]);
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $libro = $this->libro($this->get(route('insumos.consolidado', $this->consulta('control'))));
        $textos = collect($libro->getSheetByName('Alertas')->toArray())->flatten()->filter()->implode(' ');

        $this->assertStringContainsString('PRECIO_ATIPICO', $textos);
    }

    public function test_control_flags_when_the_fondo_is_exceeded(): void
    {
        $this->aprobada($this->conAprobada, [['cantidad' => 100, 'descripcion' => 'Pintura', 'precio_unitario' => 1000, 'partida_presupuestal' => '24901', 'proveedor' => 'X']]);
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $libro = $this->libro($this->get(route('insumos.consolidado', $this->consulta('control'))));
        $textos = collect($libro->getSheetByName('Alertas')->toArray())->flatten()->filter()->implode(' ');

        $this->assertStringContainsString('EXCEDE_FONDO', $textos);
        $this->assertEquals(-58000, $libro->getSheetByName('Resumen')->getCell('B12')->getValue()); // 50,000 - 108,000
    }

    // --- Tablero ---------------------------------------------------------------

    public function test_board_shows_download_panel_and_fondo_only_to_jefatura(): void
    {
        $this->escenario();

        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));
        $this->get(route('insumos.index', ['anio' => 2026, 'mes' => 11]))
            ->assertOk()
            ->assertSee('Descargar ANEXO')
            ->assertSee('Pendiente por ejercer')
            ->assertSee('Edificio Zonal Este');

        $this->actingAsTwoFactorVerified($this->userWithRole('Edificio'));
        $this->get(route('insumos.index', ['anio' => 2026, 'mes' => 11]))
            ->assertOk()
            ->assertDontSee('Descargar ANEXO')
            ->assertDontSee('Pendiente por ejercer');
    }
}
