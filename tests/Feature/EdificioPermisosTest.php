<?php

namespace Tests\Feature;

use App\Models\CatalogoInsumo;
use App\Models\Estacion;
use App\Models\SolicitudInsumo;
use App\Models\SolicitudPapeleria;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Las cuentas Edificio capturan a nombre de "Edificio Zonal Este" y revisan lo de las demás estaciones
 * (insumos y papelería), sin revisar lo propio ni consolidar/enviar a Coordinación.
 */
class EdificioPermisosTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private Estacion $eze;

    private Estacion $tulum;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->eze = Estacion::factory()->create(['nombre' => 'Edificio Zonal Este', 'codigo' => '00', 'orden' => 10]);
        $this->tulum = Estacion::factory()->create(['nombre' => 'Tulum', 'codigo' => '25', 'orden' => 8]);
    }

    private function con(string $rol, array $attrs = []): User
    {
        $user = User::factory()->create($attrs);
        $user->assignRole($rol);
        $this->actingAsTwoFactorVerified($user);

        return $user;
    }

    public function test_edificio_captures_the_insumos_of_edificio_zonal_este(): void
    {
        $edificio = $this->con('Edificio');
        $solicitud = SolicitudInsumo::factory()->create(['estacion_id' => $this->eze->id]);

        $this->assertSame($this->eze->id, $edificio->estacionPropiaId());
        $this->assertTrue($edificio->can('update', $solicitud));
        $this->assertFalse($edificio->can('review', $solicitud));

        $this->get(route('insumos.estacion', $this->eze))->assertRedirect();
        $this->assertDatabaseHas('solicitudes_insumos', ['estacion_id' => $this->eze->id]);
    }

    public function test_edificio_reviews_other_stations_but_cannot_edit_them(): void
    {
        $solicitud = SolicitudInsumo::factory()->enviada()->create(['estacion_id' => $this->tulum->id]);
        $edificio = $this->con('Edificio');

        $this->assertTrue($edificio->can('review', $solicitud));
        $this->assertFalse($edificio->can('update', $solicitud));

        $this->post(route('insumos.revisar', $solicitud), ['accion' => 'aprobar'])->assertRedirect();
        $this->assertSame('aprobada', $solicitud->refresh()->estado);
    }

    public function test_edificio_does_not_review_its_own_insumos_but_the_jefatura_does(): void
    {
        $propia = SolicitudInsumo::factory()->enviada()->create(['estacion_id' => $this->eze->id]);

        $this->assertFalse($this->con('Edificio')->can('review', $propia));
        $this->post(route('insumos.revisar', $propia), ['accion' => 'aprobar'])->assertForbidden();

        $this->assertTrue($this->con('Jefe de Zona')->can('review', $propia));
    }

    public function test_edificio_still_cannot_consolidate_or_send_to_coordinacion(): void
    {
        $this->con('Edificio');

        $this->get(route('insumos.coordinacion.index'))->assertForbidden();
        $this->get(route('insumos.consolidado', ['anio' => 2026, 'mes' => 12, 'formato' => 'anexo']))->assertForbidden();
    }

    public function test_edificio_handles_papeleria_like_insumos(): void
    {
        $edificio = $this->con('Edificio');
        $ajena = SolicitudPapeleria::factory()->enviada()->create(['estacion_id' => $this->tulum->id]);
        $propia = SolicitudPapeleria::factory()->create(['estacion_id' => $this->eze->id]);

        $this->assertTrue($edificio->can('review', $ajena));
        $this->assertFalse($edificio->can('update', $ajena));
        $this->assertTrue($edificio->can('update', $propia));
        $this->assertFalse($edificio->can('review', $propia->forceFill(['estado' => SolicitudPapeleria::ENVIADA])));

        $this->post(route('papeleria.revisar', $ajena), ['accion' => 'aprobar'])->assertRedirect();
        $this->get(route('papeleria.catalogo.index'))->assertOk();
        $this->get(route('papeleria.descargar'))->assertOk();
    }

    public function test_edificio_manages_the_insumos_catalog_and_providers(): void
    {
        $insumo = CatalogoInsumo::factory()->create();
        $this->con('Edificio');

        $this->get(route('insumos.catalogo.index'))->assertOk();
        $this->put(route('insumos.catalogo.update', $insumo), ['descripcion' => 'Nuevo nombre', 'unidad' => 'Pieza'])->assertSessionHasNoErrors();
        $this->get(route('insumos.proveedores.index'))->assertOk();
    }

    public function test_station_accounts_are_unchanged(): void
    {
        $estacion = $this->con('Estación', ['estacion_id' => $this->tulum->id]);
        $otra = SolicitudInsumo::factory()->enviada()->create(['estacion_id' => $this->eze->id]);

        $this->assertSame($this->tulum->id, $estacion->estacionPropiaId());
        $this->assertFalse($estacion->can('review', $otra));
        $this->assertFalse($estacion->can('update', $otra));
        $this->get(route('insumos.catalogo.index'))->assertForbidden();
        $this->assertNull(User::factory()->create()->estacionPropiaId());
    }
}
