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
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Feature coverage for the individual solicitud export in the official
 * Excel format (for signature). Dummy factory data only
 * (.claude/rules/testing.md).
 */
class FormatoSolicitudInsumoTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private Estacion $estacion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->estacion = Estacion::factory()->create(['nombre' => 'Playa del Carmen', 'codigo' => '24']);
    }

    private function userWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    private function solicitud(int $partidas, string $estado = SolicitudInsumo::ENVIADA, array $override = []): SolicitudInsumo
    {
        $solicitud = SolicitudInsumo::factory()->create($override + [
            'estacion_id' => $this->estacion->id, 'anio' => 2026, 'mes' => 12, 'estado' => $estado,
            'tipo_servicio' => '2do escalón', 'lugar_trabajo' => 'Playa del Carmen', 'solicitante_nombre' => 'Ing. Prueba', 'solicitante_puesto' => 'Gerente de Estación',
        ]);

        for ($i = 1; $i <= $partidas; $i++) {
            SolicitudInsumoPartida::factory()->create([
                'solicitud_insumo_id' => $solicitud->id, 'numero' => $i, 'cantidad' => $i, 'descripcion' => 'Insumo '.$i,
                'precio_unitario' => 100, 'codigo' => 'C'.$i, 'justificacion' => 'Motivo '.$i,
            ]);
        }

        return $solicitud;
    }

    private function hoja(TestResponse $respuesta): Worksheet
    {
        $ruta = tempnam(sys_get_temp_dir(), 'fmt').'.xlsx';
        file_put_contents($ruta, $respuesta->streamedContent());

        /** @var Spreadsheet $libro */
        $libro = IOFactory::load($ruta);

        return $libro->getSheetByName('SOLICITUD');
    }

    // --- Authentication / authorization ------------------------------------

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('insumos.formato', $this->solicitud(1)))->assertRedirect(route('login'));
    }

    public function test_estacion_can_only_download_its_own(): void
    {
        $propia = $this->solicitud(1);
        $ajena = SolicitudInsumo::factory()->create();
        $this->actingAsTwoFactorVerified($this->userWithRole('Estación', ['estacion_id' => $this->estacion->id]));

        $this->get(route('insumos.formato', $propia))->assertOk();
        $this->get(route('insumos.formato', $ajena))->assertForbidden();
    }

    public function test_zone_roles_can_download_any(): void
    {
        $solicitud = $this->solicitud(1);

        foreach (['Jefe de Zona', 'Administrador', 'Edificio'] as $rol) {
            $this->actingAsTwoFactorVerified($this->userWithRole($rol));

            $this->get(route('insumos.formato', $solicitud))->assertOk();
        }
    }

    // --- Contenido ----------------------------------------------------------------

    public function test_file_name_header_and_signers(): void
    {
        $solicitud = $this->solicitud(2);
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $respuesta = $this->get(route('insumos.formato', $solicitud))->assertOk();
        $this->assertStringContainsString('24_PlayaDelCarmen_SOLICITUD_DE_INSUMOS_PARA_MANTENIMIENTO_Diciembre_2026.xlsx', $respuesta->headers->get('Content-Disposition'));

        $hoja = $this->hoja($respuesta);
        $this->assertSame('Playa del Carmen', $hoja->getCell('D6')->getValue());
        $this->assertSame('DICIEMBRE 2026', $hoja->getCell('I5')->getValue());
        $this->assertNull($hoja->getCell('I6')->getValue());
        $this->assertSame('X', $hoja->getCell('I7')->getValue());
        $this->assertSame('Gerente de Estación', $hoja->getCell('B28')->getValue());
        $this->assertSame('Ing. Prueba', $hoja->getCell('B32')->getValue());
    }

    public function test_a_lugar_different_from_the_estacion_is_appended(): void
    {
        $solicitud = $this->solicitud(1, SolicitudInsumo::ENVIADA, ['lugar_trabajo' => 'Taller norte']);
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->assertSame('Playa del Carmen — Taller norte', $this->hoja($this->get(route('insumos.formato', $solicitud)))->getCell('D6')->getValue());
    }

    public function test_zone_gets_prices_and_corrected_formulas(): void
    {
        $solicitud = $this->solicitud(2);
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $hoja = $this->hoja($this->get(route('insumos.formato', $solicitud)));

        $this->assertSame('Insumo 1', $hoja->getCell('E11')->getValue());
        $this->assertEquals(100, $hoja->getCell('G11')->getValue());
        $this->assertSame('C1', $hoja->getCell('F11')->getValue());
        $this->assertSame('=C12*G12', $hoja->getCell('H12')->getValue());
        $this->assertSame('=SUM(H11:H22)', $hoja->getCell('H23')->getValue());
        $this->assertSame('=8%*H23', $hoja->getCell('H24')->getValue());
        $this->assertSame('=H23+H24', $hoja->getCell('H25')->getValue());
        $this->assertNull($hoja->getCell('B13')->getValue()); // filas de ejemplo vaciadas
        $this->assertSame('Pieza', $hoja->getCell('D11')->getValue()); // unidad de la partida
    }

    public function test_estacion_does_not_get_prices_until_approved(): void
    {
        $solicitud = $this->solicitud(1);
        $this->actingAsTwoFactorVerified($this->userWithRole('Estación', ['estacion_id' => $this->estacion->id]));

        $hoja = $this->hoja($this->get(route('insumos.formato', $solicitud)));
        $this->assertSame('Insumo 1', $hoja->getCell('E11')->getValue());
        $this->assertNull($hoja->getCell('G11')->getValue());
        $this->assertNull($hoja->getCell('H11')->getValue());
        $this->assertNull($hoja->getCell('F11')->getValue());
        $this->assertNull($hoja->getCell('H23')->getValue());

        $solicitud->update(['estado' => SolicitudInsumo::APROBADA]);

        $hoja = $this->hoja($this->get(route('insumos.formato', $solicitud)));
        $this->assertEquals(100, $hoja->getCell('G11')->getValue());
        $this->assertSame('=SUM(H11:H22)', $hoja->getCell('H23')->getValue());
    }

    public function test_more_than_twelve_partidas_insert_rows_and_move_totals_and_signers(): void
    {
        $solicitud = $this->solicitud(15);
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $hoja = $this->hoja($this->get(route('insumos.formato', $solicitud)));

        $this->assertSame('Insumo 15', $hoja->getCell('E25')->getValue());
        $this->assertSame('=SUM(H11:H25)', $hoja->getCell('H26')->getValue());
        $this->assertSame('=8%*H26', $hoja->getCell('H27')->getValue());
        $this->assertSame('=H26+H27', $hoja->getCell('H28')->getValue());
        $this->assertSame('Gerente de Estación', $hoja->getCell('B31')->getValue());
        $this->assertSame('Ing. Prueba', $hoja->getCell('B35')->getValue());
    }

    public function test_text_starting_with_equals_is_stored_as_text(): void
    {
        $solicitud = $this->solicitud(0);
        SolicitudInsumoPartida::factory()->create([
            'solicitud_insumo_id' => $solicitud->id, 'numero' => 1, 'descripcion' => '=HYPERLINK("http://x.test","clic")', 'justificacion' => '+cmd|calc',
        ]);
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $hoja = $this->hoja($this->get(route('insumos.formato', $solicitud)));

        $this->assertSame('s', $hoja->getCell('E11')->getDataType());
        $this->assertSame('=HYPERLINK("http://x.test","clic")', $hoja->getCell('E11')->getValue());
        $this->assertSame('s', $hoja->getCell('I11')->getDataType());
    }

    public function test_download_is_logged_and_the_proveedores_sheet_is_removed(): void
    {
        $solicitud = $this->solicitud(1);
        $this->actingAsTwoFactorVerified($usuario = $this->userWithRole('Jefe de Zona'));

        $respuesta = $this->get(route('insumos.formato', $solicitud))->assertOk();

        $ruta = tempnam(sys_get_temp_dir(), 'fmt').'.xlsx';
        file_put_contents($ruta, $respuesta->streamedContent());
        $this->assertSame(['SOLICITUD'], IOFactory::load($ruta)->getSheetNames());

        $this->assertTrue(Activity::query()->where('log_name', 'solicitud_insumo')->where('description', 'formato exportado')->where('causer_id', $usuario->id)->exists());
    }
}
