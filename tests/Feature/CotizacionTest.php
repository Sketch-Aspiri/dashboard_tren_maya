<?php

namespace Tests\Feature;

use App\Models\Cotizacion;
use App\Models\CotizacionRenglon;
use App\Models\Estacion;
use App\Models\Proveedor;
use App\Models\SolicitudInsumo;
use App\Models\SolicitudInsumoPartida;
use App\Models\User;
use App\Services\Asistente\ModeloLenguaje;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\Fakes\ModeloLenguajeFalso;
use Tests\TestCase;

/**
 * Feature coverage for the cotizaciones (fase A del fondo rotatorio): the
 * rules that decide when a quoted price replaces the estimated one, manual
 * accept/reject, overlapping quotes, the PDF, the "Leer con IA" proposal and
 * the PRECIOS_REALES Excel. Dummy factory data only; the Claude API is never
 * called (.claude/rules/testing.md).
 */
class CotizacionTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private SolicitudInsumoPartida $cable;

    private SolicitudInsumoPartida $cinta;

    private SolicitudInsumoPartida $pintura;

    private SolicitudInsumoPartida $aceite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Storage::fake('local');

        $estacion = Estacion::factory()->create(['nombre' => 'Puerto Morelos', 'codigo' => '23', 'orden' => 1]);
        $solicitud = SolicitudInsumo::factory()->create(['estacion_id' => $estacion->id, 'anio' => 2026, 'mes' => 12, 'estado' => SolicitudInsumo::APROBADA]);

        $this->cable = $this->partida($solicitud, 1, 'Cable primario', 2, 100);
        $this->cinta = $this->partida($solicitud, 2, 'Cinta aislante', 10, 10);
        $this->pintura = $this->partida($solicitud, 3, 'Pintura vinílica', 1, 200);
        $this->aceite = $this->partida($solicitud, 4, 'Aceite de dos tiempos', 1, 50);
    }

    private function partida(SolicitudInsumo $solicitud, int $numero, string $descripcion, float $cantidad, float $precio): SolicitudInsumoPartida
    {
        return SolicitudInsumoPartida::factory()->create([
            'solicitud_insumo_id' => $solicitud->id, 'numero' => $numero, 'descripcion' => $descripcion, 'cantidad' => $cantidad, 'precio_unitario' => $precio, 'proveedor' => 'Truper',
        ]);
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
    private function renglon(?SolicitudInsumoPartida $partida, float $cantidad, ?float $precio, array $extra = []): array
    {
        return $extra + [
            'partida_id' => $partida?->id, 'descripcion' => $partida?->descripcion ?? 'Producto cotizado', 'cantidad' => $cantidad,
            'precio_unitario' => $precio, 'coincidencia' => 'mismo',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $renglones
     * @return array<string, mixed>
     */
    private function payload(array $renglones, array $extra = []): array
    {
        return $extra + ['anio' => 2026, 'mes' => 12, 'proveedor' => 'Truper', 'folio' => 'COT-196', 'fecha' => '2026-11-10', 'renglones' => $renglones];
    }

    private function guardar(array $renglones, array $extra = []): Cotizacion
    {
        $this->post(route('insumos.cotizaciones.store'), $this->payload($renglones, $extra))->assertSessionHasNoErrors();

        return Cotizacion::query()->latest('id')->firstOrFail();
    }

    private function estatus(Cotizacion $cotizacion, SolicitudInsumoPartida $partida): CotizacionRenglon
    {
        return $cotizacion->renglones()->where('solicitud_insumo_partida_id', $partida->id)->firstOrFail();
    }

    private function libro(TestResponse $respuesta): Spreadsheet
    {
        $ruta = tempnam(sys_get_temp_dir(), 'cot').'.xlsx';
        file_put_contents($ruta, $respuesta->streamedContent());

        return IOFactory::load($ruta);
    }

    // --- Authentication / authorization ------------------------------------

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('insumos.cotizaciones.index'))->assertRedirect(route('login'));
        $this->post(route('insumos.cotizaciones.store'), [])->assertRedirect(route('login'));
        $this->postJson(route('insumos.cotizaciones.leer-ia'))->assertUnauthorized();
    }

    public function test_estacion_cannot_use_cotizaciones_but_zone_roles_and_edificio_can(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Estación', ['estacion_id' => Estacion::factory()->create()->id]));
        $this->get(route('insumos.cotizaciones.index'))->assertForbidden();
        $this->get(route('insumos.cotizaciones.create'))->assertForbidden();
        $this->post(route('insumos.cotizaciones.store'), $this->payload([$this->renglon($this->cable, 2, 100)]))->assertForbidden();
        $this->postJson(route('insumos.cotizaciones.leer-ia'), ['anio' => 2026, 'mes' => 12, 'archivo' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])->assertForbidden();

        foreach (['Jefe de Zona', 'Administrador', 'Edificio'] as $rol) {
            $this->actingAsTwoFactorVerified($this->userWithRole($rol));

            $this->get(route('insumos.cotizaciones.index', ['anio' => 2026, 'mes' => 12]))->assertOk();
            $this->get(route('insumos.cotizaciones.create', ['anio' => 2026, 'mes' => 12]))->assertOk()->assertSee('Leer con IA');
        }
    }

    // --- Reglas de actualización -------------------------------------------------

    public function test_a_matching_quote_within_25_percent_replaces_the_estimated_price_and_keeps_it(): void
    {
        $this->actingAsTwoFactorVerified($usuario = $this->userWithRole('Jefe de Zona'));

        $cotizacion = $this->guardar([$this->renglon($this->cable, 2, 110), $this->renglon(null, 3, 55)]);

        $renglon = $this->estatus($cotizacion, $this->cable);
        $this->assertSame('actualizado', $renglon->estatus);
        $this->assertSame('110.00', $this->cable->fresh()->precio_unitario);
        $this->assertSame('100.00', $this->cable->fresh()->precio_estimado);

        $fuera = $cotizacion->renglones()->whereNull('solicitud_insumo_partida_id')->firstOrFail();
        $this->assertSame('sin_vincular', $fuera->estatus);

        $this->assertSame('OK', $cotizacion->validacion);
        $this->assertTrue(Activity::query()->where('log_name', 'cotizacion')->where('subject_id', $cotizacion->id)->where('causer_id', $usuario->id)->exists());
        $this->assertNotNull(Proveedor::query()->where('nombre', 'Truper')->first()); // el proveedor queda registrado
    }

    public function test_the_25_percent_tolerance_boundary(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $dentro = $this->guardar([$this->renglon($this->cable, 2, 125)], ['folio' => 'A']);
        $this->assertSame('actualizado', $this->estatus($dentro, $this->cable)->estatus);

        $fuera = $this->guardar([$this->renglon($this->cinta, 10, 12.51)], ['folio' => 'B']);
        $this->assertSame('revisar', $this->estatus($fuera, $this->cinta)->estatus); // 25.1 % sobre 10
    }

    public function test_each_rule_sends_the_line_to_revisar_with_its_reason_and_keeps_the_price(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $cotizacion = $this->guardar([
            $this->renglon($this->cable, 2, 300),                                           // +200 %
            $this->renglon($this->cinta, 5, 10),                                            // cantidad distinta
            $this->renglon($this->pintura, 1, 200, ['coincidencia' => 'otra_marca']),
            $this->renglon($this->aceite, 1, 50, ['coincidencia' => 'distinto']),
        ]);

        $this->assertSame('PRECIO_FUERA_DE_RANGO', $this->estatus($cotizacion, $this->cable)->motivo);
        $this->assertSame('CANTIDAD_DISTINTA', $this->estatus($cotizacion, $this->cinta)->motivo);
        $this->assertSame('CAMBIO_PRODUCTO_MARCA', $this->estatus($cotizacion, $this->pintura)->motivo);
        $this->assertSame('EMPAREJAMIENTO_DUDOSO', $this->estatus($cotizacion, $this->aceite)->motivo);

        $this->assertSame('100.00', $this->cable->fresh()->precio_unitario);
        $this->assertSame('10.00', $this->cinta->fresh()->precio_unitario);
        $this->assertNull($this->cable->fresh()->precio_estimado);
    }

    public function test_a_line_without_price_goes_to_revisar(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $cotizacion = $this->guardar([$this->renglon($this->cable, 2, null)]);

        $this->assertSame('SIN_PRECIO', $this->estatus($cotizacion, $this->cable)->motivo);
    }

    // --- Aritmética de la cotización -----------------------------------------------

    public function test_a_quote_whose_numbers_do_not_add_up_blocks_every_automatic_update(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $cotizacion = $this->guardar([$this->renglon($this->cable, 2, 100, ['importe' => 200])], ['subtotal' => 999, 'iva' => 79.92, 'total' => 1078.92]);

        $this->assertSame('REVISAR', $cotizacion->validacion);
        $this->assertStringContainsString('subtotal', $cotizacion->validacion_detalle);
        $this->assertSame('COTIZACION_CON_PROBLEMAS', $this->estatus($cotizacion, $this->cable)->motivo);
        $this->assertSame('100.00', $this->cable->fresh()->precio_unitario);
    }

    public function test_iva_is_checked_against_the_providers_rate(): void
    {
        Proveedor::factory()->create(['nombre' => 'Continental', 'iva' => 16]);
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $mal = $this->guardar([$this->renglon($this->cable, 2, 100)], ['proveedor' => 'Continental', 'folio' => 'M', 'subtotal' => 200, 'iva' => 16, 'total' => 216]);
        $this->assertSame('REVISAR', $mal->validacion);
        $this->assertStringContainsString('16 %', $mal->validacion_detalle);

        $bien = $this->guardar([$this->renglon($this->cinta, 10, 10)], ['proveedor' => 'Continental', 'folio' => 'B', 'subtotal' => 100, 'iva' => 16, 'total' => 116]);
        $this->assertSame('OK', $bien->validacion);
        $this->assertSame('actualizado', $this->estatus($bien, $this->cinta)->estatus);
    }

    // --- Aceptar / rechazar a mano ---------------------------------------------------

    public function test_the_zone_can_accept_a_revisar_price_and_reject_it_again(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Edificio'));
        $cotizacion = $this->guardar([$this->renglon($this->cable, 2, 300)]);
        $renglon = $this->estatus($cotizacion, $this->cable);

        $this->post(route('insumos.cotizaciones.aceptar', $renglon))->assertRedirect(route('insumos.cotizaciones.show', $cotizacion));
        $this->assertSame('actualizado', $renglon->fresh()->estatus);
        $this->assertSame('ACEPTADO_MANUALMENTE', $renglon->fresh()->motivo);
        $this->assertSame('300.00', $this->cable->fresh()->precio_unitario);
        $this->assertSame('100.00', $this->cable->fresh()->precio_estimado);

        $this->post(route('insumos.cotizaciones.rechazar', $renglon))->assertRedirect();
        $this->assertSame('revisar', $renglon->fresh()->estatus);
        $this->assertSame('100.00', $this->cable->fresh()->precio_unitario);
        $this->assertNull($this->cable->fresh()->precio_estimado);
    }

    public function test_a_line_without_partida_or_price_cannot_be_accepted(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));
        $cotizacion = $this->guardar([$this->renglon(null, 1, 10)]);

        $this->post(route('insumos.cotizaciones.aceptar', $cotizacion->renglones()->firstOrFail()))->assertSessionHasErrors('renglon');
    }

    // --- Varias cotizaciones, edición y baja --------------------------------------------

    public function test_overlapping_quotes_keep_the_original_estimate_and_unwind_in_order(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $primera = $this->guardar([$this->renglon($this->cable, 2, 110)], ['proveedor' => 'Truper', 'folio' => '1']);
        $segunda = $this->guardar([$this->renglon($this->cable, 2, 105)], ['proveedor' => 'Continental', 'folio' => '2']);

        $this->assertSame('105.00', $this->cable->fresh()->precio_unitario);
        $this->assertSame('100.00', $this->cable->fresh()->precio_estimado);

        $this->delete(route('insumos.cotizaciones.destroy', $segunda))->assertRedirect();
        $this->assertSame('110.00', $this->cable->fresh()->precio_unitario);

        $this->delete(route('insumos.cotizaciones.destroy', $primera))->assertRedirect();
        $this->assertSame('100.00', $this->cable->fresh()->precio_unitario);
        $this->assertNull($this->cable->fresh()->precio_estimado);
        $this->assertSame(0, Cotizacion::query()->count());
    }

    public function test_editing_a_quote_reverts_the_old_prices_and_applies_the_new_ones(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));
        $cotizacion = $this->guardar([$this->renglon($this->cable, 2, 110), $this->renglon($this->cinta, 10, 11)]);

        $this->put(route('insumos.cotizaciones.update', $cotizacion), $this->payload([$this->renglon($this->cable, 2, 120)]))->assertRedirect(route('insumos.cotizaciones.show', $cotizacion));

        $this->assertSame('120.00', $this->cable->fresh()->precio_unitario);
        $this->assertSame('100.00', $this->cable->fresh()->precio_estimado);
        $this->assertSame('10.00', $this->cinta->fresh()->precio_unitario);   // quitada de la cotización: vuelve a su precio
        $this->assertNull($this->cinta->fresh()->precio_estimado);
        $this->assertSame(1, $cotizacion->fresh()->renglones()->count());
    }

    // --- Validación del formulario ---------------------------------------------------

    public function test_validation_rules(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));
        $this->guardar([$this->renglon($this->cable, 2, 110)]);

        $this->post(route('insumos.cotizaciones.store'), $this->payload([$this->renglon($this->cinta, 10, 10)]))->assertInvalid('folio');                       // proveedor + folio repetidos
        $this->post(route('insumos.cotizaciones.store'), $this->payload([], ['folio' => 'X']))->assertInvalid('renglones');
        $this->post(route('insumos.cotizaciones.store'), $this->payload([$this->renglon($this->cinta, 0, 10)], ['folio' => 'X']))->assertInvalid('renglones.0.cantidad');
        $this->post(route('insumos.cotizaciones.store'), $this->payload([['descripcion' => '', 'cantidad' => 1]], ['folio' => 'X']))->assertInvalid('renglones.0.descripcion');
        $this->post(route('insumos.cotizaciones.store'), $this->payload([$this->renglon($this->cinta, 1, 10, ['coincidencia' => 'raro'])], ['folio' => 'X']))->assertInvalid('renglones.0.coincidencia');
        $this->post(route('insumos.cotizaciones.store'), $this->payload([$this->renglon($this->cinta, 1, -5)], ['folio' => 'X']))->assertInvalid('renglones.0.precio_unitario');
        $this->post(route('insumos.cotizaciones.store'), $this->payload([$this->renglon($this->cinta, 1, 10)], ['folio' => 'X', 'mes' => 13]))->assertInvalid('mes');
    }

    public function test_a_partida_of_another_month_or_not_approved_cannot_be_quoted(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));
        $otroMes = $this->partida(SolicitudInsumo::factory()->create(['anio' => 2026, 'mes' => 11, 'estado' => SolicitudInsumo::APROBADA]), 1, 'Otro mes', 1, 10);
        $enviada = $this->partida(SolicitudInsumo::factory()->create(['anio' => 2026, 'mes' => 12, 'estado' => SolicitudInsumo::ENVIADA]), 1, 'Sin aprobar', 1, 10);
        $quitada = $this->partida($this->cable->solicitud, 9, 'Quitada', 1, 10);
        $quitada->update(['eliminada_por_coordinacion' => true]);

        foreach ([$otroMes, $enviada, $quitada] as $partida) {
            $this->post(route('insumos.cotizaciones.store'), $this->payload([$this->renglon($partida, 1, 10)], ['folio' => 'X'.$partida->id]))->assertInvalid('renglones.0.partida_id');
        }

        $this->assertSame(0, Cotizacion::query()->count());
    }

    // --- PDF ------------------------------------------------------------------------

    public function test_the_pdf_is_stored_privately_and_only_authorized_users_can_download_it(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));
        $pdf = UploadedFile::fake()->createWithContent('cotizacion.pdf', "%PDF-1.4\n%contenido de prueba\n");

        $cotizacion = $this->guardar([$this->renglon($this->cable, 2, 110)], ['archivo' => $pdf]);

        Storage::disk('local')->assertExists($cotizacion->archivo_ruta);
        $this->assertSame('cotizacion.pdf', $cotizacion->archivo_nombre);

        $this->get(route('insumos.cotizaciones.archivo', $cotizacion))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->actingAsTwoFactorVerified($this->userWithRole('Estación', ['estacion_id' => Estacion::factory()->create()->id]));
        $this->get(route('insumos.cotizaciones.archivo', $cotizacion))->assertForbidden();

        $this->actingAsTwoFactorVerified($this->userWithRole('Administrador'));
        $this->delete(route('insumos.cotizaciones.destroy', $cotizacion))->assertRedirect();
        Storage::disk('local')->assertMissing($cotizacion->archivo_ruta);
    }

    public function test_only_pdf_files_are_accepted(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->post(route('insumos.cotizaciones.store'), $this->payload([$this->renglon($this->cable, 2, 110)], ['archivo' => UploadedFile::fake()->create('virus.exe', 10, 'application/octet-stream')]))->assertInvalid('archivo');
        $this->post(route('insumos.cotizaciones.store'), $this->payload([$this->renglon($this->cable, 2, 110)], ['archivo' => UploadedFile::fake()->create('grande.pdf', 11000, 'application/pdf')]))->assertInvalid('archivo');
        $this->assertSame(0, Cotizacion::query()->count());
    }

    // --- Leer con IA --------------------------------------------------------------------

    public function test_ia_proposes_the_data_and_suggests_partidas_without_saving_anything(): void
    {
        $modelo = new ModeloLenguajeFalso;
        $this->app->instance(ModeloLenguaje::class, $modelo);
        $modelo->encolarTexto(json_encode([
            'proveedor' => 'Truper SA de CV', 'folio' => 'COT-777', 'fecha' => '2026-11-12', 'subtotal' => '$1,100.00', 'iva' => '88.00', 'total' => '1,188.00',
            'renglones' => [
                ['clave' => 'CB-01', 'descripcion' => 'Cable primario rojo', 'cantidad' => '2', 'precio_unitario' => '110.00', 'importe' => '220.00'],
                ['clave' => '', 'descripcion' => 'Zzzzqq xxwwvv', 'cantidad' => '1', 'precio_unitario' => '5', 'importe' => ''],
                ['clave' => '', 'descripcion' => '   ', 'cantidad' => '1', 'precio_unitario' => '5', 'importe' => ''],
            ],
        ]));
        $this->actingAsTwoFactorVerified($usuario = $this->userWithRole('Jefe de Zona'));

        $respuesta = $this->postJson(route('insumos.cotizaciones.leer-ia'), ['anio' => 2026, 'mes' => 12, 'archivo' => UploadedFile::fake()->createWithContent('cot.pdf', "%PDF-1.4\n")]);

        $respuesta->assertOk()->assertJsonPath('success', true)->assertJsonPath('error', null)
            ->assertJsonPath('data.proveedor', 'Truper SA de CV')->assertJsonPath('data.folio', 'COT-777')->assertJsonPath('data.fecha', '2026-11-12')
            ->assertJsonPath('data.subtotal', 1100)->assertJsonPath('data.total', 1188)
            ->assertJsonCount(2, 'data.renglones')
            ->assertJsonPath('data.renglones.0.partida_id', $this->cable->id)   // sugerida por parecido
            ->assertJsonPath('data.renglones.0.precio_unitario', 110)
            ->assertJsonPath('data.renglones.1.partida_id', null);

        $this->assertSame('document', $modelo->peticiones[0]['messages'][0]['content'][0]['type']);
        $this->assertSame('json_schema', $modelo->peticiones[0]['outputConfig']['format']['type']);
        $this->assertSame(0, Cotizacion::query()->count());
        $this->assertSame('100.00', $this->cable->fresh()->precio_unitario);
        $this->assertTrue(Activity::query()->where('event', 'lectura_ia')->where('causer_id', $usuario->id)->exists());
    }

    public function test_ia_is_reported_as_unavailable_without_an_api_key(): void
    {
        $this->app->instance(ModeloLenguaje::class, new ModeloLenguajeFalso(false));
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->get(route('insumos.cotizaciones.create', ['anio' => 2026, 'mes' => 12]))->assertOk()->assertSee('no está configurada');
        $this->postJson(route('insumos.cotizaciones.leer-ia'), ['anio' => 2026, 'mes' => 12, 'archivo' => UploadedFile::fake()->createWithContent('cot.pdf', "%PDF-1.4\n")])
            ->assertStatus(503)->assertJsonPath('error.code', 'ia_no_configurada')->assertJsonPath('success', false);
    }

    public function test_ia_errors_are_reported_without_breaking_the_form(): void
    {
        $modelo = new ModeloLenguajeFalso;
        $this->app->instance(ModeloLenguaje::class, $modelo);
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));
        $pdf = fn () => UploadedFile::fake()->createWithContent('cot.pdf', "%PDF-1.4\n");

        $modelo->encolarTexto('', 'refusal');
        $this->postJson(route('insumos.cotizaciones.leer-ia'), ['anio' => 2026, 'mes' => 12, 'archivo' => $pdf()])->assertStatus(422)->assertJsonPath('error.code', 'ia_error');

        $modelo->encolarTexto('esto no es json');
        $this->postJson(route('insumos.cotizaciones.leer-ia'), ['anio' => 2026, 'mes' => 12, 'archivo' => $pdf()])->assertStatus(422)->assertJsonPath('success', false);

        $this->postJson(route('insumos.cotizaciones.leer-ia'), ['anio' => 2026, 'mes' => 12, 'archivo' => UploadedFile::fake()->create('a.txt', 1, 'text/plain')])->assertStatus(422);
    }

    // --- Reporte en Excel ---------------------------------------------------------------------

    public function test_the_precios_reales_excel_has_the_anexo_columns_and_the_report_sheets(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));
        $this->guardar([$this->renglon($this->cable, 2, 110), $this->renglon($this->cinta, 10, 30), $this->renglon(null, 1, 5)]);

        $respuesta = $this->get(route('insumos.cotizaciones.descargar', ['anio' => 2026, 'mes' => 12]))->assertOk();
        $this->assertStringContainsString('ZO_PRECIOS_REALES_DIC_', $respuesta->headers->get('Content-Disposition'));

        $libro = $this->libro($respuesta);
        $this->assertSame(['ZO_Cotización', 'REPORTE_COTIZACIONES', 'Actualizados', 'REVISAR', 'Cotizado fuera del documento', 'Sin cotización', 'Cotizaciones'], $libro->getSheetNames());

        $anexo = $libro->getSheetByName('ZO_Cotización');
        $this->assertSame('P.U. estimado', $anexo->getCell('M9')->getValue());
        $this->assertSame('Estatus fase A', $anexo->getCell('O9')->getValue());
        $this->assertSame('Cable primario', $anexo->getCell('E10')->getValue());
        $this->assertEquals(110, $anexo->getCell('F10')->getValue());                   // precio real
        $this->assertEquals(100, $anexo->getCell('M10')->getValue());                   // estimado conservado
        $this->assertSame('Truper COT-196', $anexo->getCell('N10')->getValue());
        $this->assertSame('ACTUALIZADO', $anexo->getCell('O10')->getValue());
        $this->assertEquals(10, $anexo->getCell('F11')->getValue());                    // la cinta (+200 %) conserva su estimado
        $this->assertSame('REVISAR', $anexo->getCell('O11')->getValue());
        $this->assertStringContainsString('PRECIO_FUERA_DE_RANGO', $anexo->getCell('P11')->getValue());
        $this->assertSame('SIN COTIZACION', $anexo->getCell('O12')->getValue());

        $actualizados = $libro->getSheetByName('Actualizados');
        $this->assertSame('Cable primario', $actualizados->getCell('B2')->getValue());
        $this->assertEquals(10.0, $actualizados->getCell('E2')->getValue());            // variación %
        $this->assertSame('Producto cotizado', $libro->getSheetByName('Cotizado fuera del documento')->getCell('A2')->getValue());
        $this->assertSame('Aceite de dos tiempos', $libro->getSheetByName('Sin cotización')->getCell('B3')->getValue());
        $this->assertSame('Partidas ACTUALIZADAS con precio cotizado', $libro->getSheetByName('REPORTE_COTIZACIONES')->getCell('A4')->getValue());
        $this->assertEquals(1, $libro->getSheetByName('REPORTE_COTIZACIONES')->getCell('B4')->getValue());
    }

    public function test_the_index_summarises_the_month_and_the_board_links_to_it(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Edificio'));
        $this->guardar([$this->renglon($this->cable, 2, 110), $this->renglon($this->cinta, 10, 30)]);

        $this->get(route('insumos.cotizaciones.index', ['anio' => 2026, 'mes' => 12]))
            ->assertOk()->assertSee('1 de 4 partidas')->assertSee('COT-196')->assertSee('Truper');

        $this->get(route('insumos.index', ['anio' => 2026, 'mes' => 12]))->assertOk()->assertSee(e(route('insumos.cotizaciones.index', ['anio' => 2026, 'mes' => 12])), false);
    }

    public function test_show_and_edit_render(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));
        $cotizacion = $this->guardar([$this->renglon($this->cable, 2, 300)]);

        $this->get(route('insumos.cotizaciones.show', $cotizacion))->assertOk()->assertSee('PRECIO_FUERA_DE_RANGO')->assertSee('Aceptar precio');
        $this->get(route('insumos.cotizaciones.edit', $cotizacion))->assertOk()->assertSee('Editar cotización')->assertSee('Cable primario');
    }
}
