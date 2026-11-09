<?php

namespace Tests\Feature;

use App\Models\CatalogoInsumo;
use App\Models\Estacion;
use App\Models\SolicitudInsumo;
use App\Models\SolicitudInsumoPartida;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Feature coverage for the insumos catalog: jefatura-only maintenance, the
 * ANEXO (.xlsx) import and the catalog learning from approved solicitudes.
 * Dummy data only (.claude/rules/testing.md).
 */
class CatalogoInsumoTest extends TestCase
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
     * Minimal ANEXO: an hidden sheet that must be ignored plus "ZO_Cotización"
     * with two station blocks, subtotal/IVA rows and a "no request" row.
     */
    private function anexo(): UploadedFile
    {
        $libro = new Spreadsheet;
        $oculta = $libro->getActiveSheet();
        $oculta->setTitle('ZO_Antigua');
        $oculta->setCellValue('B10', 1)->setCellValue('E10', 'Insumo de hoja oculta')->setCellValue('F10', 999);
        $oculta->setSheetState('hidden');

        $hoja = $libro->createSheet();
        $hoja->setTitle('ZO_Cotización');
        $filas = [
            [10, 1, 2, 'carrete', 'Cable   primario rojo', 125, 24901],
            [11, 2, 10, 'PIEZA', 'Interruptor de sobreponer', 17.5, null],
            [12, 3, null, 'Pieza', 'No realizaron solicitud a la fecha', null, null],
            [13, 4, 1, 'Pieza', 'Insumo sin precio', null, null],
            [20, 1, 3, 'Pieza', 'cable primario rojo', 130, 24901],
            [21, 2, 1, 'Galon', 'Cloro granulado', 800, null],
            [22, 3, 1, 'Cuñeta', 'Pintura vinílica', 1500, null],
        ];
        foreach ($filas as [$fila, $numero, $cantidad, $unidad, $descripcion, $precio, $partida]) {
            $hoja->setCellValue('B'.$fila, $numero)->setCellValue('C'.$fila, $cantidad)->setCellValue('D'.$fila, $unidad)
                ->setCellValue('E'.$fila, $descripcion)->setCellValue('F'.$fila, $precio)->setCellValue('H'.$fila, $partida);
        }
        $hoja->setCellValue('E14', 'Subtotal')->setCellValue('F14', 500);

        $ruta = tempnam(sys_get_temp_dir(), 'anexo').'.xlsx';
        (new Xlsx($libro))->save($ruta);

        return new UploadedFile($ruta, 'ANEXO.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    // --- Authorization -----------------------------------------------------

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('insumos.catalogo.index'))->assertRedirect(route('login'));
        $this->post(route('insumos.catalogo.store'), [])->assertRedirect(route('login'));
    }

    public function test_only_jefatura_can_manage_the_catalog(): void
    {
        $insumo = CatalogoInsumo::factory()->create();
        $estacion = Estacion::factory()->create();

        foreach (['Estación', 'Edificio'] as $rol) {
            $this->actingAsTwoFactorVerified($this->userWithRole($rol, ['estacion_id' => $rol === 'Estación' ? $estacion->id : null]));

            $this->get(route('insumos.catalogo.index'))->assertForbidden();
            $this->post(route('insumos.catalogo.store'), ['descripcion' => 'X', 'unidad' => 'Pieza'])->assertForbidden();
            $this->put(route('insumos.catalogo.update', $insumo), ['descripcion' => 'X', 'unidad' => 'Pieza'])->assertForbidden();
            $this->delete(route('insumos.catalogo.destroy', $insumo))->assertForbidden();
            $this->post(route('insumos.catalogo.importar'), ['archivo' => $this->anexo()])->assertForbidden();
        }

        $this->assertDatabaseHas('catalogo_insumos', ['id' => $insumo->id]);
    }

    // --- CRUD ----------------------------------------------------------------

    public function test_jefatura_creates_updates_and_deletes_an_insumo_and_it_is_logged(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->post(route('insumos.catalogo.store'), [
            'descripcion' => 'Cinta aislante', 'unidad' => 'Rollo', 'ultimo_precio' => '35.50',
            'partida_presupuestal' => '24601', 'activo' => '1',
        ])->assertRedirect(route('insumos.catalogo.index'));

        $insumo = CatalogoInsumo::query()->where('descripcion', 'Cinta aislante')->firstOrFail();
        $this->assertTrue($insumo->activo);
        $this->assertSame('35.50', $insumo->ultimo_precio);

        $this->get(route('insumos.catalogo.index', ['q' => 'cinta']))->assertOk()->assertSee('Cinta aislante');

        $this->put(route('insumos.catalogo.update', $insumo), ['descripcion' => 'Cinta aislante 19 mm', 'unidad' => 'Rollo', 'ultimo_precio' => '40'])->assertRedirect();
        $insumo->refresh();
        $this->assertSame('Cinta aislante 19 mm', $insumo->descripcion);
        $this->assertFalse($insumo->activo); // checkbox sin marcar = inactivo

        $this->delete(route('insumos.catalogo.destroy', $insumo))->assertRedirect();
        $this->assertDatabaseMissing('catalogo_insumos', ['id' => $insumo->id]);

        $this->assertSame(3, Activity::query()->where('log_name', 'catalogo_insumo')->count());
    }

    public function test_validation_rejects_duplicates_and_bad_partida(): void
    {
        CatalogoInsumo::factory()->create(['descripcion' => 'Guantes']);
        $this->actingAsTwoFactorVerified($this->userWithRole('Administrador'));

        $this->post(route('insumos.catalogo.store'), ['descripcion' => 'Guantes', 'unidad' => 'Par'])->assertInvalid('descripcion');
        $this->post(route('insumos.catalogo.store'), ['descripcion' => 'Otro', 'unidad' => '', 'partida_presupuestal' => '12'])
            ->assertInvalid(['unidad', 'partida_presupuestal']);
        $this->post(route('insumos.catalogo.store'), ['descripcion' => 'Otro', 'unidad' => 'Pieza', 'ultimo_precio' => '-1'])->assertInvalid('ultimo_precio');
    }

    // --- Importación ----------------------------------------------------------

    public function test_import_reads_only_valid_rows_of_the_visible_cotizacion_sheet(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->post(route('insumos.catalogo.importar'), ['archivo' => $this->anexo()])
            ->assertRedirect(route('insumos.catalogo.index'))
            ->assertSessionHas('status');

        // "Cable primario rojo" aparece 2 veces (gana el último precio); sin precio y "No realizaron" se omiten.
        $this->assertSame(4, CatalogoInsumo::query()->count());
        $this->assertSame('Galón', CatalogoInsumo::query()->where('descripcion', 'Cloro granulado')->value('unidad'));
        $this->assertSame('Cuñete', CatalogoInsumo::query()->where('descripcion', 'Pintura vinílica')->value('unidad'));

        $cable = CatalogoInsumo::query()->where('descripcion', 'Cable primario rojo')->firstOrFail();
        $this->assertSame('130.00', $cable->ultimo_precio);
        $this->assertSame('24901', $cable->partida_presupuestal);

        $this->assertSame('Pieza', CatalogoInsumo::query()->where('descripcion', 'Interruptor de sobreponer')->value('unidad'));
        $this->assertDatabaseMissing('catalogo_insumos', ['descripcion' => 'Insumo de hoja oculta']);
    }

    public function test_reimporting_updates_prices_without_duplicating(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));
        CatalogoInsumo::factory()->create(['descripcion' => 'Cable primario rojo', 'unidad' => 'Metro', 'ultimo_precio' => 90, 'partida_presupuestal' => '99999']);

        $this->post(route('insumos.catalogo.importar'), ['archivo' => $this->anexo()])->assertRedirect();

        $this->assertSame(4, CatalogoInsumo::query()->count());
        $cable = CatalogoInsumo::query()->where('descripcion', 'Cable primario rojo')->firstOrFail();
        $this->assertSame('130.00', $cable->ultimo_precio);
        $this->assertSame('Metro', $cable->unidad);                  // no pisa la unidad existente
        $this->assertSame('99999', $cable->partida_presupuestal);    // no pisa una partida ya capturada
    }

    public function test_import_rejects_non_xlsx_and_broken_files(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->post(route('insumos.catalogo.importar'), ['archivo' => UploadedFile::fake()->create('datos.pdf', 10, 'application/pdf')])
            ->assertInvalid('archivo');
        $this->post(route('insumos.catalogo.importar'), [])->assertInvalid('archivo');

        $roto = UploadedFile::fake()->createWithContent('roto.xlsx', 'esto no es un excel');
        $this->post(route('insumos.catalogo.importar'), ['archivo' => $roto])->assertSessionHasErrors('archivo');
        $this->assertSame(0, CatalogoInsumo::query()->count());
    }

    // --- El catálogo aprende al aprobar --------------------------------------

    public function test_approving_adds_hand_typed_partidas_to_the_catalog_and_links_them(): void
    {
        $estacion = Estacion::factory()->create();
        $solicitud = SolicitudInsumo::factory()->enviada()->create(['estacion_id' => $estacion->id]);
        $nueva = SolicitudInsumoPartida::factory()->create([
            'solicitud_insumo_id' => $solicitud->id, 'numero' => 1, 'descripcion' => 'Machete 18 pulgadas', 'unidad' => 'Pieza',
        ]);
        $existente = CatalogoInsumo::factory()->create(['descripcion' => 'Rastrillo', 'unidad' => 'Pieza', 'ultimo_precio' => 100]);
        $enlazada = SolicitudInsumoPartida::factory()->create([
            'solicitud_insumo_id' => $solicitud->id, 'numero' => 2, 'descripcion' => 'Rastrillo', 'catalogo_insumo_id' => $existente->id,
        ]);
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->post(route('insumos.revisar', $solicitud), [
            'accion' => 'aprobar',
            'partidas' => [
                $nueva->id => ['precio_unitario' => '210', 'partida_presupuestal' => '24901', 'proveedor' => 'Truper'],
                $enlazada->id => ['precio_unitario' => '150', 'partida_presupuestal' => '24901'],
            ],
        ])->assertRedirect();

        $machete = CatalogoInsumo::query()->where('descripcion', 'Machete 18 pulgadas')->firstOrFail();
        $this->assertSame('210.00', $machete->ultimo_precio);
        $this->assertSame('Truper', $machete->proveedor);
        $this->assertSame($machete->id, $nueva->fresh()->catalogo_insumo_id);

        $this->assertSame('150.00', $existente->fresh()->ultimo_precio);
        $this->assertSame(2, CatalogoInsumo::query()->count());
    }

    public function test_saving_without_approving_does_not_touch_the_catalog(): void
    {
        $solicitud = SolicitudInsumo::factory()->enviada()->create();
        $partida = SolicitudInsumoPartida::factory()->create(['solicitud_insumo_id' => $solicitud->id]);
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->post(route('insumos.revisar', $solicitud), [
            'accion' => 'guardar',
            'partidas' => [$partida->id => ['precio_unitario' => '10', 'partida_presupuestal' => '24901']],
        ])->assertRedirect();

        $this->assertSame(0, CatalogoInsumo::query()->count());
    }
}
