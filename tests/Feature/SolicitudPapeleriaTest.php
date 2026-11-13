<?php

namespace Tests\Feature;

use App\Models\CatalogoPapeleria;
use App\Models\Estacion;
use App\Models\SolicitudPapeleria;
use App\Models\User;
use App\Services\PapeleriaLibro;
use App\Services\SolicitudPapeleriaService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Feature coverage for "Recursos materiales" -> Solicitud de papelería.
 * Dummy factory data only (.claude/rules/testing.md).
 */
class SolicitudPapeleriaTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function userWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return ['partidas' => [
            ['articulo' => 'PAPEL BOND TAMAÑO CARTA', 'unidad' => 'CAJA', 'cantidad' => '2', 'comentarios' => 'Para impresión'],
            ['articulo' => 'LIGAS DE HULE', 'unidad' => 'BOLSA', 'cantidad' => '1', 'comentarios' => null],
        ]];
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('papeleria.index'))->assertRedirect(route('login'));
    }

    public function test_station_is_redirected_to_its_own_request_and_cannot_open_another(): void
    {
        $suya = Estacion::factory()->create();
        $otra = Estacion::factory()->create();
        $this->actingAsTwoFactorVerified($this->userWithRole('Estación', ['estacion_id' => $suya->id]));

        $this->get(route('papeleria.index'))->assertRedirect();
        $this->get(route('papeleria.estacion', $otra))->assertForbidden();
        $this->get(route('papeleria.show', SolicitudPapeleria::factory()->create(['estacion_id' => $otra->id])))->assertForbidden();
    }

    public function test_station_saves_and_sends_its_request_with_audit_log(): void
    {
        $estacion = Estacion::factory()->create();
        $usuario = $this->actingAsTwoFactorVerified($this->userWithRole('Estación', ['estacion_id' => $estacion->id]));
        $solicitud = SolicitudPapeleria::factory()->create(['estacion_id' => $estacion->id]);

        $this->put(route('papeleria.update', $solicitud), $this->payload())->assertRedirect(route('papeleria.show', $solicitud));
        $this->assertDatabaseHas('solicitud_papeleria_partidas', ['solicitud_papeleria_id' => $solicitud->id, 'numero' => 2, 'articulo' => 'LIGAS DE HULE', 'unidad' => 'BOLSA']);

        $this->post(route('papeleria.enviar', $solicitud))->assertRedirect();
        $this->assertSame(SolicitudPapeleria::ENVIADA, $solicitud->refresh()->estado);
        $this->assertTrue(Activity::query()->where('log_name', 'solicitud_papeleria')->where('causer_id', $usuario->id)->where('description', 'enviada')->exists());
    }

    public function test_station_cannot_send_empty_request_or_invalid_unit(): void
    {
        $estacion = Estacion::factory()->create();
        $this->actingAsTwoFactorVerified($this->userWithRole('Estación', ['estacion_id' => $estacion->id]));
        $solicitud = SolicitudPapeleria::factory()->create(['estacion_id' => $estacion->id]);

        $this->post(route('papeleria.enviar', $solicitud))->assertSessionHasErrors('partidas');
        $this->put(route('papeleria.update', $solicitud), ['partidas' => [['articulo' => 'X', 'unidad' => 'GALON', 'cantidad' => '1']]])
            ->assertInvalid(['partidas.0.unidad']);
        $this->put(route('papeleria.update', $solicitud), ['partidas' => [['articulo' => 'X', 'unidad' => 'PIEZA', 'cantidad' => '0']]])
            ->assertInvalid(['partidas.0.cantidad']);
    }

    public function test_station_cannot_edit_after_sending(): void
    {
        $estacion = Estacion::factory()->create();
        $this->actingAsTwoFactorVerified($this->userWithRole('Estación', ['estacion_id' => $estacion->id]));
        $solicitud = SolicitudPapeleria::factory()->enviada()->create(['estacion_id' => $estacion->id]);

        $this->put(route('papeleria.update', $solicitud), $this->payload())->assertForbidden();
    }

    public function test_chief_returns_with_comment_and_approves(): void
    {
        $solicitud = SolicitudPapeleria::factory()->enviada()->create();
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->post(route('papeleria.revisar', $solicitud), ['accion' => 'devolver'])->assertInvalid(['comentario_zona']);
        $this->post(route('papeleria.revisar', $solicitud), ['accion' => 'devolver', 'comentario_zona' => 'Falta la cantidad'])->assertRedirect();
        $this->assertSame(SolicitudPapeleria::OBSERVADA, $solicitud->refresh()->estado);

        $solicitud->update(['estado' => SolicitudPapeleria::ENVIADA]);
        $this->post(route('papeleria.revisar', $solicitud), ['accion' => 'aprobar'])->assertRedirect();
        $this->assertSame(SolicitudPapeleria::APROBADA, $solicitud->refresh()->estado);
    }

    public function test_building_role_and_station_cannot_review_or_download(): void
    {
        $solicitud = SolicitudPapeleria::factory()->enviada()->create();

        $this->actingAsTwoFactorVerified($this->userWithRole('Edificio'));
        $this->post(route('papeleria.revisar', $solicitud), ['accion' => 'aprobar'])->assertForbidden();
        $this->get(route('papeleria.descargar'))->assertForbidden();
        $this->get(route('papeleria.catalogo.index'))->assertForbidden();

        $this->actingAsTwoFactorVerified($this->userWithRole('Estación', ['estacion_id' => $solicitud->estacion_id]));
        $this->get(route('papeleria.descargar'))->assertForbidden();
    }

    public function test_chief_downloads_excel_in_official_format_with_only_approved(): void
    {
        $estacion = Estacion::factory()->create(['nombre' => 'Bacalar', 'orden' => 1]);
        $otra = Estacion::factory()->create(['nombre' => 'Chetumal', 'orden' => 2]);
        $aprobada = SolicitudPapeleria::factory()->aprobada()->create(['estacion_id' => $estacion->id]);
        $aprobada->partidas()->create(['numero' => 1, 'articulo' => '=HYPERLINK("x")', 'unidad' => 'PIEZA', 'cantidad' => 3, 'comentarios' => 'urgente']);
        $enviada = SolicitudPapeleria::factory()->enviada()->create(['estacion_id' => $otra->id]);
        $enviada->partidas()->create(['numero' => 1, 'articulo' => 'NO DEBE SALIR', 'unidad' => 'CAJA', 'cantidad' => 1]);
        CatalogoPapeleria::query()->create(['descripcion' => 'LIGAS DE HULE']);

        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));
        $respuesta = $this->get(route('papeleria.descargar', ['anio' => 2026, 'mes' => 12]));
        $respuesta->assertOk();
        $this->assertStringContainsString('SOLICITUD PAPELERIA_DIC_', $respuesta->headers->get('content-disposition'));

        $libro = app(PapeleriaLibro::class)->resumen(app(SolicitudPapeleriaService::class)->filasAprobadas(2026, 12));
        $hoja = $libro->getSheetByName('Resumen');
        $this->assertSame('SOLICITUD PAPELERIA', $hoja->getCell('A1')->getValue());
        $this->assertSame(['No.', 'Estacion/Edificio', 'Articulo', 'Unidad de medida', 'Cantidad', 'Comentarios'],
            array_map(fn ($c) => $hoja->getCell($c.'3')->getValue(), ['A', 'B', 'C', 'D', 'E', 'F']));
        $this->assertSame('BACALAR', $hoja->getCell('B4')->getValue());
        $this->assertSame('=HYPERLINK("x")', $hoja->getCell('C4')->getValue());
        $this->assertSame('s', $hoja->getCell('C4')->getDataType());
        $this->assertNull($hoja->getCell('B5')->getValue());
        $this->assertSame('hidden', $libro->getSheetByName('CATALOGOS')->getSheetState());

        $tmp = tempnam(sys_get_temp_dir(), 'pap').'.xlsx';
        (new Xlsx($libro))->save($tmp);
        $this->assertNotNull(IOFactory::load($tmp)->getSheetByName('Resumen'));
        unlink($tmp);
    }

    public function test_catalog_command_is_idempotent_and_chief_manages_catalog(): void
    {
        $this->artisan('papeleria:cargar-catalogo')->assertSuccessful();
        $total = CatalogoPapeleria::query()->count();
        $this->assertGreaterThan(50, $total);
        $this->artisan('papeleria:cargar-catalogo')->assertSuccessful();
        $this->assertSame($total, CatalogoPapeleria::query()->count());

        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));
        $this->post(route('papeleria.catalogo.store'), ['descripcion' => 'GRAPAS NUEVAS'])->assertRedirect();
        $this->post(route('papeleria.catalogo.store'), ['descripcion' => 'GRAPAS NUEVAS'])->assertInvalid(['descripcion']);
        $articulo = CatalogoPapeleria::query()->where('descripcion', 'GRAPAS NUEVAS')->firstOrFail();
        $this->put(route('papeleria.catalogo.update', $articulo), ['descripcion' => 'GRAPAS']);
        $this->assertFalse($articulo->refresh()->activo);
        $this->get(route('papeleria.catalogo.index'))->assertOk();
    }

    public function test_pages_render_for_station_and_chief(): void
    {
        $estacion = Estacion::factory()->create();
        $solicitud = SolicitudPapeleria::factory()->create(['estacion_id' => $estacion->id]);

        $this->actingAsTwoFactorVerified($this->userWithRole('Estación', ['estacion_id' => $estacion->id]));
        $this->get(route('papeleria.show', $solicitud))->assertOk()->assertSee('Agregar artículo');

        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));
        $this->get(route('papeleria.index', ['anio' => 2026, 'mes' => 12]))->assertOk()->assertSee('Descargar Excel');
        $this->get(route('papeleria.show', $solicitud))->assertOk();
    }
}
