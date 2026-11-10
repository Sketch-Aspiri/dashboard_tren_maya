<?php

namespace Tests\Feature;

use App\Models\CatalogoInsumo;
use App\Models\Estacion;
use App\Models\InventarioEstacion;
use App\Models\Proveedor;
use App\Models\SolicitudInsumo;
use App\Models\SolicitudInsumoPartida;
use App\Models\User;
use App\Services\InventarioEstacionService;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Inventario de las estaciones (resguardos): comparación de lo que se pide con
 * lo que ya tienen, pantalla de consulta, verificación en vivo, marca en la
 * revisión de la Zona y carga de datos (inventario, partidas y proveedores).
 */
class InventarioEstacionTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private Estacion $estacion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        CarbonImmutable::setTestNow('2026-11-10');
        $this->estacion = Estacion::factory()->create(['nombre' => 'Bacalar', 'orden' => 1]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function usuario(string $rol, ?Estacion $estacion = null): User
    {
        $usuario = User::factory()->create(['estacion_id' => $estacion?->id]);
        $usuario->assignRole($rol);

        return $usuario;
    }

    private function item(string $descripcion, string $tipo = 'HERRAMIENTA', float $cantidad = 1, string $mes = '2026-04', ?Estacion $estacion = null): InventarioEstacion
    {
        return InventarioEstacion::factory()->create([
            'estacion_id' => ($estacion ?? $this->estacion)->id, 'tipo' => $tipo, 'descripcion' => $descripcion,
            'clave' => InventarioEstacionService::clave($descripcion), 'cantidad' => $cantidad, 'primer_mes' => $mes, 'ultimo_mes' => $mes,
        ]);
    }

    // --- Comparación -----------------------------------------------------------------------------

    public function test_clave_normaliza_acentos_plurales_y_palabras_vacias(): void
    {
        $this->assertSame('machete', InventarioEstacionService::clave('Machetes'));
        $this->assertSame(InventarioEstacionService::clave('Llave de 12” para plomero'), InventarioEstacionService::clave('LLAVE 12" PARA PLOMERO'));
        $this->assertSame('luminario ultradelgado redondo empotrar 18w luz dia', InventarioEstacionService::clave('Luminario ultradelgado redondo p/empotrar 18W, luz de día'));
    }

    public function test_parecido_distingue_mismo_articulo_de_otro_distinto(): void
    {
        $parecido = fn (string $a, string $b) => InventarioEstacionService::parecido(InventarioEstacionService::clave($a), InventarioEstacionService::clave($b));

        $this->assertSame(1.0, $parecido('Machete', 'machetes'));
        $this->assertGreaterThanOrEqual(0.6, $parecido('Brochas de 2 pulgadas', 'Brochas # 2'));
        $this->assertLessThan(0.6, $parecido('Brocha de 4 pulgadas', 'Brochas # 2'));
        $this->assertLessThan(0.6, $parecido('Lima triangular para afilar machete', 'Machete'));
        $this->assertLessThan(0.6, $parecido('Pintura blanca', 'Cinta de aislar negra'));
    }

    public function test_avisa_cuando_la_estacion_ya_tiene_la_herramienta(): void
    {
        $this->item('Machete', cantidad: 3, mes: '2026-05');
        $servicio = app(InventarioEstacionService::class);

        $avisos = $servicio->avisos($this->estacion, 'Machetes para las áreas verdes de mantenimiento');
        $this->assertSame([], $avisos, 'una descripcion larga con otras palabras no es el mismo articulo');

        $avisos = $servicio->avisos($this->estacion, 'machete');
        $this->assertCount(1, $avisos);
        $this->assertSame('YA_TIENE', $avisos[0]['tipo']);
        $this->assertStringContainsString('Ya cuenta con 3', $avisos[0]['mensaje']);
        $this->assertStringContainsString('mayo 2026', $avisos[0]['mensaje']);
    }

    public function test_consumibles_solo_avisan_si_se_entregaron_hace_poco(): void
    {
        $this->item('Cinta de aislar negra', 'CONSUMIBLE', 4, '2026-10');
        $this->item('Silicón transparente', 'CONSUMIBLE', 2, '2026-02');
        $servicio = app(InventarioEstacionService::class);

        $reciente = $servicio->avisos($this->estacion, 'Cinta de aislar negra');
        $this->assertSame('ENTREGA_RECIENTE', $reciente[0]['tipo']);
        $this->assertStringContainsString('octubre 2026', $reciente[0]['mensaje']);
        $this->assertSame([], $servicio->avisos($this->estacion, 'Silicón transparente'), 'una entrega de hace 9 meses ya no cuenta');
    }

    public function test_no_mezcla_el_inventario_de_otras_estaciones(): void
    {
        $otra = Estacion::factory()->create();
        $this->item('Machete', estacion: $otra);

        $this->assertSame([], app(InventarioEstacionService::class)->avisos($this->estacion, 'Machete'));
    }

    // --- Pantallas y JSON ------------------------------------------------------------------------

    public function test_estacion_ve_solo_su_inventario_y_la_zona_cualquiera(): void
    {
        $otra = Estacion::factory()->create(['nombre' => 'Tulum']);
        $this->item('Machete');
        $this->item('Pala de punta', estacion: $otra);

        $this->actingAsTwoFactorVerified($this->usuario('Estación', $this->estacion));
        $this->get(route('insumos.inventario.index'))->assertOk()->assertSee('Machete')->assertDontSee('Pala de punta');
        $this->get(route('insumos.inventario.index', ['estacion' => $otra->id]))->assertForbidden();

        $this->actingAsTwoFactorVerified($this->usuario('Jefe de Zona'));
        $this->get(route('insumos.inventario.index', ['estacion' => $otra->id]))->assertOk()->assertSee('Pala de punta')->assertDontSee('Machete');
        $this->get(route('insumos.inventario.index', ['estacion' => $this->estacion->id, 'tipo' => 'EPP']))->assertOk()->assertDontSee('Machete');
        $this->get(route('insumos.inventario.index', ['tipo' => 'cualquiera']))->assertInvalid('tipo');
    }

    public function test_verificar_devuelve_los_avisos_en_json_con_permiso_de_estacion(): void
    {
        $this->item('Machete', cantidad: 2);
        $otra = Estacion::factory()->create();

        $this->actingAsTwoFactorVerified($this->usuario('Estación', $this->estacion));
        $this->getJson(route('insumos.inventario.verificar', ['estacion' => $this->estacion->id, 'descripcion' => 'Machete']))
            ->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.0.tipo', 'YA_TIENE');
        $this->getJson(route('insumos.inventario.verificar', ['estacion' => $otra->id, 'descripcion' => 'Machete']))->assertForbidden();
        $this->getJson(route('insumos.inventario.verificar', ['estacion' => $this->estacion->id]))->assertOk()->assertJsonPath('data', []);
    }

    public function test_invitados_son_redirigidos_al_login(): void
    {
        $this->get(route('insumos.inventario.index'))->assertRedirect(route('login'));
        $this->getJson(route('insumos.inventario.verificar'))->assertUnauthorized();
    }

    public function test_la_solicitud_muestra_el_aviso_a_la_estacion_y_a_la_zona(): void
    {
        $this->item('Machete', cantidad: 3, mes: '2026-05');
        $solicitud = SolicitudInsumo::factory()->create(['estacion_id' => $this->estacion->id, 'anio' => 2026, 'mes' => 11, 'estado' => SolicitudInsumo::ENVIADA]);
        SolicitudInsumoPartida::factory()->create(['solicitud_insumo_id' => $solicitud->id, 'numero' => 1, 'descripcion' => 'Machete', 'justificacion' => 'Se rompió']);

        $this->actingAsTwoFactorVerified($this->usuario('Jefe de Zona'));
        $this->get(route('insumos.show', $solicitud))->assertOk()->assertSee('Ya cuenta con 3')->assertSee('Inventario:');

        $solicitud->update(['estado' => SolicitudInsumo::BORRADOR]);
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $this->estacion));
        $this->get(route('insumos.show', $solicitud))->assertOk()->assertSee('Ya cuenta con 3')->assertSee('insumos/inventario/verificar', false);
    }

    public function test_la_zona_ve_la_partida_del_catalogo_como_sugerencia(): void
    {
        $catalogo = CatalogoInsumo::factory()->create(['descripcion' => 'Pintura de prueba', 'partida_presupuestal' => '24901']);
        $solicitud = SolicitudInsumo::factory()->create(['estacion_id' => $this->estacion->id, 'anio' => 2026, 'mes' => 11, 'estado' => SolicitudInsumo::ENVIADA]);
        SolicitudInsumoPartida::factory()->create(['solicitud_insumo_id' => $solicitud->id, 'numero' => 1, 'descripcion' => 'Pintura de prueba', 'catalogo_insumo_id' => $catalogo->id, 'partida_presupuestal' => null]);

        $this->actingAsTwoFactorVerified($this->usuario('Jefe de Zona'));
        $this->get(route('insumos.show', $solicitud))->assertOk()->assertSee('value="24901"', false);
    }

    /** Los archivos de database/data no van en git (traen RFC y datos de resguardos): se copian al servidor aparte. */
    private function saltarSinDatos(): void
    {
        if (! is_file(database_path('data/inventario_estaciones.json')) || ! is_file(database_path('data/proveedores_fondo.json'))) {
            $this->markTestSkipped('Faltan los archivos de database/data (no se versionan).');
        }
    }

    // --- Carga de datos --------------------------------------------------------------------------

    public function test_la_carga_es_idempotente_y_no_pisa_lo_capturado(): void
    {
        $this->saltarSinDatos();

        foreach (['Bacalar', 'Chetumal', 'Kohunlich'] as $i => $nombre) {
            Estacion::query()->updateOrCreate(['nombre' => $nombre], ['codigo' => (string) (40 + $i), 'is_operativa' => true, 'orden' => $i + 2]);
        }
        $existente = Proveedor::query()->create(['nombre' => 'Continental', 'iva' => 8]);
        $sinPartida = CatalogoInsumo::factory()->create(['descripcion' => 'Cinta doble cara de 19 mm x 5 m, PRETUL', 'partida_presupuestal' => null]);
        $conPartida = CatalogoInsumo::factory()->create(['descripcion' => 'Aceite de dos tiempos', 'partida_presupuestal' => '29101']);

        $this->artisan('insumos:cargar-datos')->assertSuccessful();
        $primera = [InventarioEstacion::query()->count(), Proveedor::query()->count()];

        $this->assertGreaterThan(100, $primera[0]);
        $this->assertGreaterThanOrEqual(13, $primera[1]);
        $this->assertSame(8, $existente->refresh()->iva, 'el IVA configurado no se toca');
        $this->assertSame('GFC051130D29', $existente->rfc);
        $this->assertNotNull($existente->giro);
        $this->assertNotNull($sinPartida->refresh()->partida_presupuestal);
        $this->assertSame('29101', $conPartida->refresh()->partida_presupuestal, 'no se cambia una partida ya asignada');

        $this->artisan('insumos:cargar-datos')->assertSuccessful();
        $this->assertSame($primera, [InventarioEstacion::query()->count(), Proveedor::query()->count()]);
    }

    public function test_la_simulacion_no_guarda_nada(): void
    {
        $this->saltarSinDatos();

        Estacion::query()->updateOrCreate(['nombre' => 'Bacalar'], ['codigo' => '29', 'is_operativa' => true, 'orden' => 1]);

        $this->artisan('insumos:cargar-datos', ['--simular' => true])->assertSuccessful();

        $this->assertSame(0, InventarioEstacion::query()->count());
        $this->assertSame(0, Proveedor::query()->count());
    }

    public function test_los_datos_cargados_tienen_las_diez_estaciones_y_proveedores_con_rfc(): void
    {
        $this->saltarSinDatos();

        $datos = json_decode((string) file_get_contents(database_path('data/inventario_estaciones.json')), true);
        $proveedores = json_decode((string) file_get_contents(database_path('data/proveedores_fondo.json')), true);

        $this->assertCount(10, array_unique(array_column($datos, 'estacion')));
        $this->assertCount(13, $proveedores);

        foreach ($proveedores as $proveedor) {
            $this->assertMatchesRegularExpression('/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/u', $proveedor['rfc']);
        }
    }
}
