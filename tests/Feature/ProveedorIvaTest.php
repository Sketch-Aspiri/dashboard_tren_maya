<?php

namespace Tests\Feature;

use App\Models\CatalogoInsumo;
use App\Models\Estacion;
use App\Models\Proveedor;
use App\Models\SolicitudInsumo;
use App\Models\SolicitudInsumoPartida;
use App\Models\User;
use App\Services\SolicitudInsumoService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Feature coverage for "Proveedores e IVA" (8 % / 16 % per provider) and the
 * monthly limit per estación ($5,000 with IVA). Dummy factory data only
 * (.claude/rules/testing.md).
 */
class ProveedorIvaTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private Estacion $estacion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->estacion = Estacion::factory()->create(['nombre' => 'Bacalar', 'codigo' => '29']);
    }

    private function userWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    /**
     * @param  list<array{0: float, 1: float, 2: string|null}>  $partidas  [cantidad, precio, proveedor]
     */
    private function solicitud(array $partidas, string $estado = SolicitudInsumo::BORRADOR): SolicitudInsumo
    {
        $solicitud = SolicitudInsumo::factory()->create(['estacion_id' => $this->estacion->id, 'anio' => 2026, 'mes' => 12, 'estado' => $estado]);

        foreach ($partidas as $i => [$cantidad, $precio, $proveedor]) {
            SolicitudInsumoPartida::factory()->create([
                'solicitud_insumo_id' => $solicitud->id, 'numero' => $i + 1, 'cantidad' => $cantidad,
                'precio_unitario' => $precio, 'proveedor' => $proveedor, 'partida_presupuestal' => '24901',
            ]);
        }

        return $solicitud;
    }

    private function hoja(TestResponse $respuesta, string $nombre): Worksheet
    {
        $ruta = tempnam(sys_get_temp_dir(), 'iva').'.xlsx';
        file_put_contents($ruta, $respuesta->streamedContent());

        return IOFactory::load($ruta)->getSheetByName($nombre);
    }

    // --- Proveedores: acceso ---------------------------------------------------

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('insumos.proveedores.index'))->assertRedirect(route('login'));
        $this->post(route('insumos.proveedores.store'), [])->assertRedirect(route('login'));
    }

    public function test_only_jefatura_can_manage_providers(): void
    {
        $proveedor = Proveedor::factory()->create();

        foreach (['Estación', 'Edificio'] as $rol) {
            $this->actingAsTwoFactorVerified($this->userWithRole($rol, ['estacion_id' => $rol === 'Estación' ? $this->estacion->id : null]));

            $this->get(route('insumos.proveedores.index'))->assertForbidden();
            $this->post(route('insumos.proveedores.store'), ['nombre' => 'X'])->assertForbidden();
            $this->put(route('insumos.proveedores.update', $proveedor), ['iva' => 16])->assertForbidden();
            $this->delete(route('insumos.proveedores.destroy', $proveedor))->assertForbidden();
        }

        foreach (['Jefe de Zona', 'Administrador'] as $rol) {
            $this->actingAsTwoFactorVerified($this->userWithRole($rol));

            $this->get(route('insumos.proveedores.index'))->assertOk();
        }
    }

    // --- Proveedores: alta, IVA, baja --------------------------------------------

    public function test_index_picks_up_providers_from_catalog_and_partidas_without_iva(): void
    {
        CatalogoInsumo::factory()->create(['proveedor' => 'Continental']);
        $this->solicitud([[1, 10, 'Truper']]);
        $this->actingAsTwoFactorVerified($this->userWithRole('Administrador'));

        $this->get(route('insumos.proveedores.index'))->assertOk()->assertSee('Continental')->assertSee('Truper')->assertSee('2 proveedores sin IVA definido');

        $this->assertNull(Proveedor::query()->where('nombre', 'Continental')->value('iva'));
        $this->assertSame(2, Proveedor::query()->count());

        $this->get(route('insumos.proveedores.index'))->assertOk();
        $this->assertSame(2, Proveedor::query()->count()); // idempotente
    }

    public function test_administrador_creates_sets_iva_and_it_is_logged(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Administrador'));

        $this->post(route('insumos.proveedores.store'), ['nombre' => '  Grupo   Ferretero  ', 'iva' => '16'])->assertRedirect(route('insumos.proveedores.index'));
        $proveedor = Proveedor::query()->firstOrFail();
        $this->assertSame('Grupo Ferretero', $proveedor->nombre);
        $this->assertSame(16, $proveedor->iva);

        $this->put(route('insumos.proveedores.update', $proveedor), ['iva' => '8', 'nombre' => 'Otro nombre'])->assertRedirect();
        $proveedor->refresh();
        $this->assertSame(8, $proveedor->iva);
        $this->assertSame('Grupo Ferretero', $proveedor->nombre); // el nombre no se renombra

        $this->put(route('insumos.proveedores.update', $proveedor), ['iva' => ''])->assertRedirect();
        $this->assertNull($proveedor->fresh()->iva);

        $this->assertSame(3, Activity::query()->where('log_name', 'proveedor')->count());
    }

    public function test_validation_only_accepts_8_or_16_and_unique_names(): void
    {
        Proveedor::factory()->create(['nombre' => 'Truper']);
        $proveedor = Proveedor::factory()->create();
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->post(route('insumos.proveedores.store'), ['nombre' => 'Truper'])->assertInvalid('nombre');
        $this->post(route('insumos.proveedores.store'), ['nombre' => ''])->assertInvalid('nombre');
        $this->post(route('insumos.proveedores.store'), ['nombre' => 'Nuevo', 'iva' => '10'])->assertInvalid('iva');
        $this->put(route('insumos.proveedores.update', $proveedor), ['iva' => '12'])->assertInvalid('iva');
        $this->put(route('insumos.proveedores.update', $proveedor), ['iva' => 'abc'])->assertInvalid('iva');
    }

    public function test_provider_in_use_cannot_be_deleted_but_an_unused_one_can(): void
    {
        $usado = Proveedor::factory()->create(['nombre' => 'Continental']);
        $libre = Proveedor::factory()->create(['nombre' => 'Sin uso']);
        CatalogoInsumo::factory()->create(['proveedor' => 'Continental']);
        $this->actingAsTwoFactorVerified($this->userWithRole('Administrador'));

        $this->delete(route('insumos.proveedores.destroy', $usado))->assertSessionHasErrors('proveedor');
        $this->assertDatabaseHas('proveedores', ['id' => $usado->id]);

        $this->delete(route('insumos.proveedores.destroy', $libre))->assertRedirect();
        $this->assertDatabaseMissing('proveedores', ['id' => $libre->id]);
    }

    public function test_provider_typed_by_the_zone_when_reviewing_is_registered_without_iva(): void
    {
        $solicitud = $this->solicitud([[1, 10, null]], SolicitudInsumo::ENVIADA);
        $partida = $solicitud->partidas()->firstOrFail();
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->post(route('insumos.revisar', $solicitud), [
            'accion' => 'guardar', 'partidas' => [$partida->id => ['precio_unitario' => '10', 'partida_presupuestal' => '24901', 'proveedor' => 'Yanci Sala']],
        ])->assertRedirect();

        $this->assertNull(Proveedor::query()->where('nombre', 'Yanci Sala')->firstOrFail()->iva);
    }

    // --- IVA por proveedor ----------------------------------------------------

    public function test_totals_split_the_iva_by_provider(): void
    {
        Proveedor::factory()->create(['nombre' => 'Continental', 'iva' => 16]);
        Proveedor::factory()->create(['nombre' => 'Truper', 'iva' => 8]);
        $solicitud = $this->solicitud([[10, 100, 'continental'], [10, 100, 'Truper'], [1, 50, null], [1, 50, 'Desconocido']]);

        $totales = app(SolicitudInsumoService::class)->totales($solicitud->load('partidas'));

        $this->assertSame(2100.0, $totales['subtotal']);
        // 1000*16 % + 1000*8 % + 50*8 % (sin proveedor) + 50*8 % (sin IVA definido -> 8 % por defecto)
        $this->assertSame(248.0, $totales['iva']);
        $this->assertSame(2348.0, $totales['total']);

        $porClave = collect($totales['desglose'])->keyBy('clave');
        $this->assertSame(16, $porClave['continental']['tasa']);
        $this->assertTrue($porClave['continental']['configurada']);
        $this->assertFalse($porClave['desconocido']['configurada']);
        $this->assertCount(4, $totales['desglose']);
    }

    public function test_anexo_has_one_iva_row_per_provider_with_its_rate(): void
    {
        Proveedor::factory()->create(['nombre' => 'Continental', 'iva' => 16]);
        Proveedor::factory()->create(['nombre' => 'Truper', 'iva' => 8]);
        $this->estacion->update(['codigo' => '23']);
        $this->solicitud([[10, 100, 'Continental'], [10, 100, 'Truper'], [5, 20, 'Continental']], SolicitudInsumo::APROBADA);
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $hoja = $this->hoja($this->get(route('insumos.consolidado', ['anio' => 2026, 'mes' => 12, 'tipo' => 'anexo'])), 'ZO_Cotización');

        $this->assertSame('=SUM(G10:G12)', $hoja->getCell('G13')->getValue());
        $this->assertSame('IVA 16 % — Continental', $hoja->getCell('E14')->getValue());
        $this->assertSame('=16%*(G10+G12)', $hoja->getCell('G14')->getValue());
        $this->assertSame('IVA 8 % — Truper', $hoja->getCell('E15')->getValue());
        $this->assertSame('=8%*(G11)', $hoja->getCell('G15')->getValue());
        $this->assertSame('=SUM(G13:G15)', $hoja->getCell('G16')->getValue());
        $this->assertSame('=SUM(G16)', $hoja->getCell('K3')->getValue());
        // Ya con el IVA de cada uno: 1100*16 % + 1000*8 % = 256
        $this->assertEquals(2356, $hoja->getCell('G16')->getCalculatedValue());
    }

    // --- Límite por estación ------------------------------------------------------

    public function test_estacion_sees_its_limit_and_budget_box_while_capturing(): void
    {
        CatalogoInsumo::factory()->create(['descripcion' => 'Cable', 'ultimo_precio' => 100, 'proveedor' => 'Continental']);
        Proveedor::factory()->create(['nombre' => 'Continental', 'iva' => 16]);
        $solicitud = $this->solicitud([]);
        $this->actingAsTwoFactorVerified($this->userWithRole('Estación', ['estacion_id' => $this->estacion->id]));

        $this->get(route('insumos.show', $solicitud))
            ->assertOk()
            ->assertSee('Límite de la estación (con IVA)')
            ->assertSee('Te quedan')
            ->assertSee('limite: 5000', false)
            ->assertSee('"precio":100', false)
            ->assertSee('"iva":16', false);
    }

    public function test_estacion_cannot_send_when_the_total_with_iva_exceeds_5000(): void
    {
        $solicitud = $this->solicitud([[60, 100, null]]); // 6,000 + 8 % = 6,480
        $this->actingAsTwoFactorVerified($this->userWithRole('Estación', ['estacion_id' => $this->estacion->id]));

        $this->post(route('insumos.enviar', $solicitud))->assertSessionHasErrors('partidas');
        $this->assertSame('borrador', $solicitud->fresh()->estado);
    }

    public function test_the_limit_counts_the_iva_of_each_provider(): void
    {
        Proveedor::factory()->create(['nombre' => 'Continental', 'iva' => 16]);
        $this->actingAsTwoFactorVerified($this->userWithRole('Estación', ['estacion_id' => $this->estacion->id]));

        $justo = $this->solicitud([[43, 100, 'Continental']]); // 4,300 * 1.16 = 4,988
        $this->post(route('insumos.enviar', $justo))->assertSessionHasNoErrors();
        $this->assertSame('enviada', $justo->fresh()->estado);

        SolicitudInsumo::query()->delete();

        $pasado = $this->solicitud([[44, 100, 'Continental']]); // 4,400 * 1.16 = 5,104 > 5,000 (con 8 % cabría: 4,752)
        $this->post(route('insumos.enviar', $pasado))->assertSessionHasErrors('partidas');
        $this->assertSame('borrador', $pasado->fresh()->estado);
    }

    public function test_zone_can_still_approve_above_the_limit_and_the_control_flags_it(): void
    {
        $solicitud = $this->solicitud([[60, 100, 'Truper']], SolicitudInsumo::ENVIADA);
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->post(route('insumos.revisar', $solicitud), ['accion' => 'aprobar'])->assertRedirect();
        $this->assertSame('aprobada', $solicitud->fresh()->estado);

        $hoja = $this->hoja($this->get(route('insumos.consolidado', ['anio' => 2026, 'mes' => 12, 'tipo' => 'control'])), 'Alertas');
        $textos = collect($hoja->toArray())->flatten()->filter()->implode(' ');

        $this->assertStringContainsString('EXCEDE_LIMITE_ESTACION', $textos);
        $this->assertStringContainsString('IVA_NO_CONFIGURADO', $textos);
    }
}
