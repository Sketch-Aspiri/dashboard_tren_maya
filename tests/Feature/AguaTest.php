<?php

namespace Tests\Feature;

use App\Models\AguaCompra;
use App\Models\AguaRegistro;
use App\Models\Estacion;
use App\Models\SolicitudInsumo;
use App\Models\User;
use App\Services\Agua\AguaImportador;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Registro de agua (garrafones): programa por estación y mes, compras con comprobante, envío y revisión.
 */
class AguaTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private Estacion $tulum;

    private Estacion $bacalar;

    private Estacion $eze;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Storage::fake('local');
        $this->bacalar = Estacion::factory()->create(['nombre' => 'Bacalar', 'codigo' => '29', 'orden' => 1]);
        $this->tulum = Estacion::factory()->create(['nombre' => 'Tulum', 'codigo' => '25', 'orden' => 8]);
        $this->eze = Estacion::factory()->create(['nombre' => 'Edificio Zonal Este', 'codigo' => '00', 'orden' => 10]);
    }

    private function con(string $rol, array $attrs = []): User
    {
        $user = User::factory()->create($attrs);
        $user->assignRole($rol);
        $this->actingAsTwoFactorVerified($user);

        return $user;
    }

    private function registro(Estacion $estacion, array $attrs = []): AguaRegistro
    {
        return AguaRegistro::query()->create($attrs + [
            'estacion_id' => $estacion->id, 'estado' => 'borrador', 'anio' => now('America/Cancun')->year, 'mes' => now('America/Cancun')->month,
            'frecuencia' => 'quincenal', 'consumo_mensual' => 11, 'costo_unitario' => 44, 'referencia' => 'TREN1',
        ]);
    }

    private function compra(AguaRegistro $registro, array $attrs = []): array
    {
        return $attrs + [
            'fecha' => now('America/Cancun')->startOfMonth()->toDateString(),
            'garrafones' => 5,
            'nota' => 'Compra de prueba',
            'comprobante' => UploadedFile::fake()->create('ticket.pdf', 100, 'application/pdf'),
        ];
    }

    public function test_station_registers_a_purchase_with_calculated_amount_and_sends_it(): void
    {
        $registro = $this->registro($this->tulum);
        $this->con('Estación', ['estacion_id' => $this->tulum->id]);

        $this->post(route('agua.compras.store', $registro), $this->compra($registro))->assertSessionHasNoErrors();

        $compra = $registro->compras()->firstOrFail();
        $this->assertEquals(220, $compra->monto);
        $this->assertSame('captura', $compra->origen);
        Storage::disk('local')->assertExists($compra->comprobante_ruta);

        $registro->refresh();
        $this->assertSame(484.0, $registro->cuota());
        $this->assertSame(-264.0, $registro->saldo()); // 220 - 484: pendiente de entrega

        $this->post(route('agua.enviar', $registro))->assertRedirect();
        $this->assertSame('enviada', $registro->refresh()->estado);
        $this->post(route('agua.compras.store', $registro), $this->compra($registro))->assertForbidden();
    }

    public function test_the_client_cannot_set_the_amount_and_validations_apply(): void
    {
        $registro = $this->registro($this->tulum);
        $this->con('Estación', ['estacion_id' => $this->tulum->id]);

        $this->post(route('agua.compras.store', $registro), $this->compra($registro, ['monto' => 1, 'garrafones' => 2]))->assertSessionHasNoErrors();
        $this->assertEquals(88, $registro->compras()->first()->monto);

        $this->post(route('agua.compras.store', $registro), $this->compra($registro, ['garrafones' => 0]))->assertSessionHasErrors('garrafones');
        $this->post(route('agua.compras.store', $registro), $this->compra($registro, ['comprobante' => null]))->assertSessionHasErrors('comprobante');
        $this->post(route('agua.compras.store', $registro), $this->compra($registro, ['comprobante' => UploadedFile::fake()->create('virus.exe', 10)]))->assertSessionHasErrors('comprobante');
        $this->post(route('agua.compras.store', $registro), $this->compra($registro, ['fecha' => now('America/Cancun')->addDays(3)->toDateString()]))->assertSessionHasErrors('fecha');
        $this->post(route('agua.compras.store', $registro), $this->compra($registro, ['fecha' => now('America/Cancun')->subMonths(2)->toDateString()]))->assertSessionHasErrors('fecha');
        $this->assertSame(1, $registro->compras()->count());
    }

    public function test_purchase_cap_and_missing_cost_are_enforced(): void
    {
        config(['agua.max_compras' => 2]);
        $registro = $this->registro($this->tulum);
        $this->con('Estación', ['estacion_id' => $this->tulum->id]);

        foreach ([1, 2] as $i) {
            $this->post(route('agua.compras.store', $registro), $this->compra($registro))->assertSessionHasNoErrors();
        }

        $this->post(route('agua.compras.store', $registro), $this->compra($registro))->assertSessionHasErrors('compra');

        $sinCosto = $this->registro($this->bacalar, ['costo_unitario' => 0]);
        $this->con('Estación', ['estacion_id' => $this->bacalar->id]);
        $this->post(route('agua.compras.store', $sinCosto), $this->compra($sinCosto))->assertSessionHasErrors('compra');
    }

    public function test_a_station_cannot_touch_or_see_another_station(): void
    {
        $ajeno = $this->registro($this->bacalar);
        $compra = $ajeno->compras()->create(['garrafones' => 1, 'costo_unitario' => 44, 'monto' => 44, 'comprobante_ruta' => 'agua/comprobantes/x.pdf']);
        Storage::disk('local')->put('agua/comprobantes/x.pdf', 'pdf');
        $this->con('Estación', ['estacion_id' => $this->tulum->id]);

        $this->get(route('agua.show', $ajeno))->assertForbidden();
        $this->get(route('agua.comprobante', [$ajeno, $compra]))->assertForbidden();
        $this->post(route('agua.compras.store', $ajeno), $this->compra($ajeno))->assertForbidden();
        $this->delete(route('agua.compras.destroy', [$ajeno, $compra]))->assertForbidden();
        $this->post(route('agua.enviar', $ajeno))->assertForbidden();
        $this->get(route('agua.estacion', $this->bacalar))->assertForbidden();
        $this->get(route('agua.descargar'))->assertForbidden();
        $this->put(route('agua.programa', $ajeno), ['frecuencia' => 'semanal', 'consumo_mensual' => 1, 'costo_unitario' => 1, 'garrafones_propios' => 0, 'garrafones_comodato' => 0])->assertForbidden();
    }

    public function test_station_removes_a_purchase_and_its_file_while_editable(): void
    {
        $registro = $this->registro($this->tulum);
        $this->con('Estación', ['estacion_id' => $this->tulum->id]);
        $this->post(route('agua.compras.store', $registro), $this->compra($registro));
        $compra = $registro->compras()->firstOrFail();
        $ruta = $compra->comprobante_ruta;

        $this->get(route('agua.comprobante', [$registro, $compra]))->assertOk();
        $this->delete(route('agua.compras.destroy', [$registro, $compra]))->assertRedirect();

        $this->assertDatabaseMissing('agua_compras', ['id' => $compra->id]);
        Storage::disk('local')->assertMissing($ruta);
        $this->post(route('agua.enviar', $registro))->assertSessionHasErrors('compra');
    }

    public function test_zone_sets_the_program_reviews_and_returns_with_comment(): void
    {
        $registro = $this->registro($this->tulum);
        $this->con('Estación', ['estacion_id' => $this->tulum->id]);
        $this->post(route('agua.compras.store', $registro), $this->compra($registro));
        $this->post(route('agua.enviar', $registro));

        $this->con('Jefe de Zona');
        $this->put(route('agua.programa', $registro), ['frecuencia' => 'semanal', 'consumo_mensual' => 20, 'costo_unitario' => 37.5, 'garrafones_propios' => 2, 'garrafones_comodato' => 6, 'referencia' => 'ABC', 'dia_entrega' => 'Jueves'])->assertSessionHasNoErrors();
        $this->assertSame(750.0, $registro->refresh()->cuota());
        $this->put(route('agua.programa', $registro), ['frecuencia' => 'diaria', 'consumo_mensual' => 20, 'costo_unitario' => 37.5, 'garrafones_propios' => 2, 'garrafones_comodato' => 6])->assertSessionHasErrors('frecuencia');

        $this->post(route('agua.revisar', $registro), ['accion' => 'devolver', 'comentario_zona' => ''])->assertSessionHasErrors('comentario_zona');
        $this->post(route('agua.revisar', $registro), ['accion' => 'devolver', 'comentario_zona' => 'Falta ticket'])->assertSessionHasNoErrors();
        $this->assertSame('observada', $registro->refresh()->estado);

        // La estación puede corregir y volver a enviar; la Zona aprueba.
        $this->con('Estación', ['estacion_id' => $this->tulum->id]);
        $this->post(route('agua.compras.store', $registro), $this->compra($registro))->assertSessionHasNoErrors();
        $this->post(route('agua.enviar', $registro));
        $this->con('Administrador');
        $this->post(route('agua.revisar', $registro), ['accion' => 'aprobar'])->assertSessionHasNoErrors();
        $this->assertSame('aprobada', $registro->refresh()->estado);
        $this->assertTrue(Activity::query()->where('log_name', 'agua')->exists());
    }

    public function test_edificio_captures_its_own_and_reviews_everything(): void
    {
        $propio = $this->registro($this->eze);
        $ajeno = $this->registro($this->tulum, ['estado' => 'enviada']);
        $edificio = $this->con('Edificio');

        $this->assertTrue($edificio->can('update', $propio));
        $this->assertFalse($edificio->can('update', $ajeno));
        $this->post(route('agua.compras.store', $propio), $this->compra($propio))->assertSessionHasNoErrors();
        $this->post(route('agua.revisar', $ajeno), ['accion' => 'aprobar'])->assertSessionHasNoErrors();
        $this->assertSame('aprobada', $ajeno->refresh()->estado);

        $this->post(route('agua.enviar', $propio));
        $this->post(route('agua.revisar', $propio), ['accion' => 'aprobar'])->assertSessionHasNoErrors();
        $this->assertSame('aprobada', $propio->refresh()->estado);
    }

    public function test_a_new_month_inherits_the_previous_program_and_does_not_touch_insumos(): void
    {
        $this->registro($this->tulum, ['anio' => 2026, 'mes' => 10, 'consumo_mensual' => 18, 'costo_unitario' => 44, 'referencia' => 'TLM3', 'dia_entrega' => 'Jueves o viernes']);
        $user = $this->con('Estación', ['estacion_id' => $this->tulum->id]);

        $this->get(route('agua.estacion', ['estacion' => $this->tulum->id, 'anio' => 2026, 'mes' => 11]))->assertRedirect();

        $nuevo = AguaRegistro::query()->where(['anio' => 2026, 'mes' => 11])->firstOrFail();
        $this->assertSame(18, $nuevo->consumo_mensual);
        $this->assertSame('TLM3', $nuevo->referencia);
        $this->assertSame('borrador', $nuevo->estado);
        $this->assertSame(0, SolicitudInsumo::query()->count());
        $this->assertNotNull($user->id);
    }

    public function test_station_account_lands_on_its_own_record_and_zone_sees_the_board(): void
    {
        $this->con('Estación', ['estacion_id' => $this->tulum->id]);
        $this->get(route('agua.index'))->assertRedirect(route('agua.estacion', ['estacion' => $this->tulum->id, 'anio' => now('America/Cancun')->year, 'mes' => now('America/Cancun')->month]));

        $this->registro($this->bacalar);
        $this->con('Jefe de Zona');
        $this->get(route('agua.index'))->assertOk()->assertSee('Bacalar')->assertSee('Tulum')->assertSee('Edificio Zonal Este');
        $this->get(route('agua.show', AguaRegistro::query()->firstOrFail()))->assertOk()->assertSee('Programa del mes');
    }

    public function test_guests_and_personal_accounts_are_kept_out(): void
    {
        $this->get(route('agua.index'))->assertRedirect(route('login'));
        $this->con('Personal');
        $this->get(route('agua.index'))->assertForbidden();
    }

    public function test_excel_has_programs_payments_and_formulas(): void
    {
        $r = $this->registro($this->tulum, ['estado' => 'aprobada', 'dia_entrega' => '=CMD()']);
        $r->compras()->create(['garrafones' => 5, 'costo_unitario' => 44, 'monto' => 220]);
        $r->compras()->create(['garrafones' => 2, 'costo_unitario' => 44, 'monto' => 88]);
        $this->con('Jefe de Zona');

        $respuesta = $this->get(route('agua.descargar'));
        $respuesta->assertOk();
        $ruta = tempnam(sys_get_temp_dir(), 'agua').'.xlsx';
        file_put_contents($ruta, $respuesta->streamedContent());
        $hoja = IOFactory::load($ruta)->getActiveSheet();

        $this->assertSame('ÁREA / ESTACIÓN', $hoja->getCell('A3')->getValue());
        $this->assertSame('BACALAR', $hoja->getCell('A4')->getValue());
        $this->assertSame('TULUM', $hoja->getCell('A5')->getValue());
        $this->assertSame(220.0, (float) $hoja->getCell('I5')->getValue());
        $this->assertSame(88.0, (float) $hoja->getCell('J5')->getValue());
        $this->assertSame('=SUM(I5:L5)', $hoja->getCell('M5')->getValue());
        $this->assertSame('=C5*D5', $hoja->getCell('N5')->getValue());
        $this->assertSame('=M5-N5', $hoja->getCell('O5')->getValue());
        $this->assertSame('Aprobada', $hoja->getCell('P5')->getValue());
        // Un texto con "=" no se evalúa como fórmula.
        $this->assertSame('=CMD()', $hoja->getCell('H5')->getValue());
        $this->assertSame('s', $hoja->getCell('H5')->getDataType());
        @unlink($ruta);
    }

    public function test_importer_reads_the_zone_workbook_by_labels(): void
    {
        $libro = new Spreadsheet;
        foreach ([['Septiembre ', 308], ['Octubre', 220]] as $i => [$titulo, $pago]) {
            $hoja = $i === 0 ? $libro->getActiveSheet() : $libro->createSheet();
            $hoja->setTitle($titulo);
            $hoja->fromArray(['ÁREA / ESTACIÓN', 'FRECUENCIA DE COMPRA ', 'CONSUMO MENSUAL NETO ', 'COSTO UNITARIO (Liquido)', 'GARRAFONES PROPIOS ', 'GARROFONES A COMODATO', 'GARRAFONES PRIMERA QUICENA', 'GASTO', 'x', 'y', 'z', 'w', 'v', 'TOTAL MENSUAL', 'REFERENCIA'], null, 'A1');
            $hoja->fromArray(['ESTACIÓN TULUM', 'QUINCENAL', 18, 44, 3, 9, 9, '=G2*D2', 9, '=I2*D2', null, null, null, '=SUM(H2+J2)', 'TLMTRENMAYA3'], null, 'A2');
            $hoja->fromArray(['ESTACIÓN NICOLÁS BRAVO', 'QUINCENAL', 12, 38.5, 2, 7, 6, '', 6, '', null, null, null, '', 'ESTACIONTRENCHT3'], null, 'A3');
            $hoja->fromArray(['EDIFICIO ZONAL', 'SEMANAL', 41, 38.5, 4, 10, 10], null, 'A9');
            $hoja->fromArray(['', 'PRIMER PAGO', 'SEGUNDO PAGO', 'TERCER PAGO', 'CUARTO PAGO', 'TOTAL', 'CUOTA ASIGNADA', 'PENDIENTE DE ENTREGA O REINTEGRO', '', 'Día de entrega'], null, 'A19');
            $hoja->fromArray(['ESTACIÓN TULUM', $pago, 176, null, null, '=SUM(B20:E20)', 792, '', '', 'Jueves o viernes'], null, 'A20');
            $hoja->fromArray(['ESTACIÓN NICOLÁS BRAVO', 231, null, null, null, '', 462, '', '', 'Lunes'], null, 'A21');
            $hoja->fromArray(['EDIFICIO ZONAL', 423.5, 423.5, null, null, '', 1578.5, '', '', 'Jueves'], null, 'A22');
            $hoja->fromArray(['ESTACIÓN FANTASMA', 100], null, 'A23');
        }
        $ruta = tempnam(sys_get_temp_dir(), 'agua').'.xlsx';
        (new Xlsx($libro))->save($ruta);

        $kohunlich = Estacion::factory()->create(['nombre' => 'Kohunlich', 'codigo' => '31', 'orden' => 4]);
        $importador = app(AguaImportador::class);
        $leido = $importador->leer($ruta, 2026);

        $this->assertCount(6, $leido['registros']); // 3 estaciones × 2 meses
        $this->assertSame(['ESTACIÓN FANTASMA'], array_values($leido['sin_estacion']));
        $septiembre = collect($leido['registros'])->firstWhere(fn ($r) => $r['mes'] === 9 && $r['estacion_id'] === $this->tulum->id);
        $this->assertSame('aprobada', $septiembre['estado']);
        $this->assertSame([308.0, 176.0], $septiembre['pagos']);
        $this->assertSame('TLMTRENMAYA3', $septiembre['referencia']);
        $this->assertSame('Jueves o viernes', $septiembre['dia_entrega']);
        $this->assertSame('borrador', collect($leido['registros'])->firstWhere(fn ($r) => $r['mes'] === 10 && $r['estacion_id'] === $kohunlich->id)['estado']);

        $r = $importador->aplicar($leido);
        $this->assertSame(['creados' => 6, 'omitidos' => 0, 'compras' => 10], $r);
        $tulumSep = AguaRegistro::query()->where(['estacion_id' => $this->tulum->id, 'mes' => 9])->firstOrFail();
        $this->assertSame(7, $tulumSep->compras->first()->garrafones); // 308 / 44
        $this->assertSame('importacion', $tulumSep->compras->first()->origen);
        $this->assertSame(792.0, $tulumSep->cuota());

        // Repetir no duplica.
        $this->assertSame(['creados' => 0, 'omitidos' => 6, 'compras' => 0], $importador->aplicar($leido));
        $this->assertSame(10, AguaCompra::query()->count());
        @unlink($ruta);
    }

    public function test_command_simulates_without_writing(): void
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle('Noviembre');
        $hoja->fromArray(['ÁREA / ESTACIÓN', 'FRECUENCIA DE COMPRA', 'CONSUMO MENSUAL NETO', 'COSTO UNITARIO', 'GARRAFONES PROPIOS', 'GARROFONES A COMODATO'], null, 'A1');
        $hoja->fromArray(['ESTACIÓN TULUM', 'QUINCENAL', 18, 44, 3, 9], null, 'A2');
        $hoja->fromArray(['', 'PRIMER PAGO', 'SEGUNDO PAGO', 'TERCER PAGO', 'CUARTO PAGO'], null, 'A19');
        $hoja->fromArray(['ESTACIÓN TULUM', 308], null, 'A20');
        $ruta = tempnam(sys_get_temp_dir(), 'agua').'.xlsx';
        (new Xlsx($libro))->save($ruta);

        $this->artisan('agua:cargar-programa', ['archivo' => $ruta, '--anio' => 2026, '--simular' => true])->expectsOutputToContain('no se guardó nada')->assertSuccessful();
        $this->assertSame(0, AguaRegistro::query()->count());

        $this->artisan('agua:cargar-programa', ['archivo' => $ruta, '--anio' => 2026])->expectsOutputToContain('Creados 1 registros')->assertSuccessful();
        $this->assertSame(1, AguaRegistro::query()->count());
        $this->artisan('agua:cargar-programa', ['archivo' => $ruta.'.no'])->assertFailed();
        @unlink($ruta);
    }
}
