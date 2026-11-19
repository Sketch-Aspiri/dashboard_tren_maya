<?php

namespace Tests\Feature;

use App\Models\Estacion;
use App\Models\User;
use App\Models\VacacionPeriodo;
use App\Models\VacacionSolicitud;
use App\Models\VacacionTramo;
use App\Services\Vacaciones\VacacionCalculo;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use DateTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Módulo Vacaciones: la Zona sube el rol del año, cada estación ve el suyo y pide cambios por envío,
 * la Zona autoriza o rechaza el envío completo. Datos ficticios con la forma del Excel real.
 */
class VacacionesTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private Estacion $morelos;

    private Estacion $tulum;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Storage::fake('local');
        $this->morelos = Estacion::factory()->create(['nombre' => 'Puerto Morelos', 'codigo' => '23']);
        $this->tulum = Estacion::factory()->create(['nombre' => 'Tulum', 'codigo' => '25']);
    }

    private function usuario(string $rol, ?Estacion $estacion = null): User
    {
        $user = User::factory()->create(array_filter(['estacion_id' => $estacion?->id]));
        $user->assignRole($rol);

        return $user;
    }

    private function dia(string $fecha): float
    {
        return Date::dateTimeToExcel(new DateTime($fecha));
    }

    /**
     * @param  list<array{0: int, 1: string, 2: int, 3: list<array{0: string, 1: string, 2: int}|null>}>  $personas
     * @return list<list<mixed>>
     */
    private function hoja(array $personas): array
    {
        $filas = [
            [null, null, null, null, null, null, 'Primer semestre'],
            [null, null, null, null, null, null, 'Primer trimestre'],
            [null, null, 'NO.', 'NO. EMPLEADO', 'Nombre Completo', 'Días otorgados', 'Inicio', 'Termino', 'Días Solicitados', 'Inicio ', 'Termino ', 'Días Solicitados ', 'Inicio  ', 'Termino  ', 'Días Solicitados  ', 'inicio    ', 'Termino    ', 'Días Solicitados    ', 'Total días solicitados', 'Estación', 'Zona', 'Denominación de puesto'],
        ];

        foreach ($personas as $i => [$emp, $nombre, $otorgados, $tramos]) {
            $fila = [null, null, $i + 1, $emp, $nombre, $otorgados];
            $total = 0;

            foreach ($tramos as $t) {
                $fila = array_merge($fila, $t === null ? [null, null, 0] : [$this->dia($t[0]), $this->dia($t[1]), $t[2]]);
                $total += $t[2] ?? 0;
            }

            $filas[] = array_merge($fila, [$total, 'ESTACION', 'Oriente', 'Gerente de Estación']);
        }

        return $filas;
    }

    /**
     * @return array<string, list<list<mixed>>>
     */
    private function rol(): array
    {
        return [
            'PUERTO MORELOS' => $this->hoja([
                [241232, 'Ana Morelos', 20, [['2026-01-05', '2026-01-09', 5], ['2026-03-09', '2026-03-20', 10], ['2026-09-07', '2026-09-11', 5], null]],
                [241300, 'Beto Morelos', 20, [null, ['2026-04-13', '2026-04-17', 5], null, null]],
            ]),
            'TULUM' => $this->hoja([
                [240428, 'Carla Tulum', 20, [['2026-02-02', '2026-02-06', 5], null, null, null]],
            ]),
            'CONSOLIDAD DGTZO' => $this->hoja([[241232, 'Ana Morelos', 20, [null, null, null, null]]]),
        ];
    }

    private function excel(array $hojas): UploadedFile
    {
        $libro = new Spreadsheet;
        $libro->removeSheetByIndex(0);

        foreach ($hojas as $titulo => $filas) {
            $hoja = $libro->createSheet()->setTitle($titulo);

            foreach ($filas as $i => $fila) {
                foreach ($fila as $j => $valor) {
                    $hoja->setCellValue([$j + 1, $i + 1], $valor);
                }
            }
        }

        $ruta = tempnam(sys_get_temp_dir(), 'vac');
        (new Xlsx($libro))->save($ruta);
        $bytes = (string) file_get_contents($ruta);
        unlink($ruta);

        return UploadedFile::fake()->createWithContent('Rol vacaciones 2026.xlsx', $bytes);
    }

    private function publicar(?array $hojas = null): VacacionPeriodo
    {
        $this->actingAsTwoFactorVerified($this->usuario('Edificio'));

        $respuesta = $this->post(route('vacaciones.importar.store'), ['archivo' => $this->excel($hojas ?? $this->rol())]);
        $token = basename((string) $respuesta->headers->get('Location'));
        $this->post(route('vacaciones.importar.aplicar'), ['token' => $token])->assertSessionHasNoErrors();

        return VacacionPeriodo::query()->where('estado', VacacionPeriodo::VIGENTE)->firstOrFail();
    }

    private function tramo(string $empleado, int $trimestre): VacacionTramo
    {
        return VacacionTramo::query()->whereHas('persona', fn ($q) => $q->where('no_empleado', $empleado))->where('trimestre', $trimestre)->firstOrFail();
    }

    public function test_dias_habiles_cuentan_de_lunes_a_viernes(): void
    {
        $this->assertSame(5, VacacionCalculo::diasHabiles(CarbonImmutable::parse('2026-04-13'), CarbonImmutable::parse('2026-04-17')));
        $this->assertSame(6, VacacionCalculo::diasHabiles(CarbonImmutable::parse('2026-05-04'), CarbonImmutable::parse('2026-05-11')));
        $this->assertSame(11, VacacionCalculo::diasHabiles(CarbonImmutable::parse('2026-05-04'), CarbonImmutable::parse('2026-05-18')));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('vacaciones.index'))->assertRedirect(route('login'));
        $this->get(route('vacaciones.importar.create'))->assertRedirect(route('login'));
    }

    public function test_estacion_cannot_upload_or_download(): void
    {
        $periodo = $this->publicar();
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $this->morelos));

        $this->get(route('vacaciones.importar.create'))->assertForbidden();
        $this->get(route('vacaciones.consolidado.descargar', $periodo))->assertForbidden();
    }

    public function test_zona_uploads_preview_then_publishes_skipping_consolidated_sheet(): void
    {
        $this->actingAsTwoFactorVerified($this->usuario('Edificio'));

        $respuesta = $this->post(route('vacaciones.importar.store'), ['archivo' => $this->excel($this->rol())]);
        $this->get($respuesta->headers->get('Location'))->assertOk()->assertSee('2026')->assertSee('CONSOLIDAD DGTZO');
        $this->assertDatabaseCount('vacacion_personas', 0);

        $periodo = $this->publicar();

        $this->assertSame(2026, $periodo->anio);
        $this->assertDatabaseCount('vacacion_personas', 3);
        $this->assertDatabaseCount('vacacion_tramos', 12);
        $this->assertSame(10, $this->tramo('241232', 2)->dias);
        $this->assertSame($this->morelos->id, $this->tramo('241232', 1)->estacion_id);
    }

    public function test_upload_rejects_non_excel_and_empty_files(): void
    {
        $this->actingAsTwoFactorVerified($this->usuario('Edificio'));

        $this->post(route('vacaciones.importar.store'), ['archivo' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])->assertInvalid('archivo');
        $this->post(route('vacaciones.importar.store'), ['archivo' => $this->excel(['Hoja' => [['nada']]])])->assertInvalid('archivo');
    }

    public function test_estacion_only_sees_its_own_people(): void
    {
        $this->publicar();
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $this->morelos));

        $this->get(route('vacaciones.index'))->assertOk()->assertSee('Ana Morelos')->assertDontSee('Carla Tulum');
    }

    public function test_estacion_proposes_and_nothing_changes_until_zona_authorizes(): void
    {
        $this->publicar();
        $estacion = $this->usuario('Estación', $this->morelos);
        $periodo = VacacionPeriodo::query()->firstOrFail();
        $tramo = $this->tramo('241300', 2);
        $this->actingAsTwoFactorVerified($estacion);

        $this->put(route('vacaciones.tramos.cambiar', $periodo), ['tramos' => [$tramo->id => ['inicio' => '2026-04-20', 'fin' => '2026-04-24']], 'motivo' => 'Cubre cierre'])
            ->assertSessionHasNoErrors();

        $this->assertSame('2026-04-13', $tramo->fresh()->inicio->toDateString());
        $solicitud = VacacionSolicitud::query()->firstOrFail();
        $this->assertSame(VacacionSolicitud::PENDIENTE, $solicitud->estado);

        $this->actingAsTwoFactorVerified($this->usuario('Edificio'));
        $this->post(route('vacaciones.lotes.autorizar', $solicitud->lote))->assertSessionHasNoErrors();

        $this->assertSame('2026-04-20', $tramo->fresh()->inicio->toDateString());
        $this->assertSame('ajustado', $tramo->fresh()->origen);
        $this->assertSame('2026-04-13', $tramo->fresh()->inicio_oficial->toDateString());
        $this->assertSame(VacacionSolicitud::AUTORIZADA, $solicitud->fresh()->estado);
    }

    public function test_estacion_needs_a_reason_and_cannot_touch_another_station(): void
    {
        $this->publicar();
        $periodo = VacacionPeriodo::query()->firstOrFail();
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $this->morelos));

        $propio = $this->tramo('241300', 2);
        $this->put(route('vacaciones.tramos.cambiar', $periodo), ['tramos' => [$propio->id => ['inicio' => '2026-04-20', 'fin' => '2026-04-24']]])->assertInvalid('motivo');

        $ajeno = $this->tramo('240428', 1);
        $this->put(route('vacaciones.tramos.cambiar', $periodo), ['tramos' => [$ajeno->id => ['inicio' => '2026-02-09', 'fin' => '2026-02-13']], 'motivo' => 'x'])->assertForbidden();
    }

    public function test_changes_are_rejected_when_they_overlap_or_exceed_granted_days(): void
    {
        $this->publicar();
        $periodo = VacacionPeriodo::query()->firstOrFail();
        $this->actingAsTwoFactorVerified($this->usuario('Edificio'));

        // Traslape con el 2.º trimestre de Ana (9 al 20 de marzo).
        $uno = $this->tramo('241232', 1);
        $this->put(route('vacaciones.tramos.cambiar', $periodo), ['tramos' => [$uno->id => ['inicio' => '2026-03-16', 'fin' => '2026-03-18']]])->assertInvalid('tramos');

        // Ana ya usa 20 días: otro tramo de 5 los excede.
        $cuatro = $this->tramo('241232', 4);
        $this->put(route('vacaciones.tramos.cambiar', $periodo), ['tramos' => [$cuatro->id => ['inicio' => '2026-11-02', 'fin' => '2026-11-06']]])->assertInvalid('tramos');

        // Inicio sin término y fuera del año.
        $this->put(route('vacaciones.tramos.cambiar', $periodo), ['tramos' => [$cuatro->id => ['inicio' => '2026-11-02', 'fin' => null]]])->assertInvalid('tramos');
        $beto = $this->tramo('241300', 3);
        $this->put(route('vacaciones.tramos.cambiar', $periodo), ['tramos' => [$beto->id => ['inicio' => '2027-01-04', 'fin' => '2027-01-08']]])->assertInvalid('tramos');

        $this->assertNull($cuatro->fresh()->inicio);
    }

    public function test_zona_edits_directly_and_zona_can_reject_with_comment(): void
    {
        $this->publicar();
        $periodo = VacacionPeriodo::query()->firstOrFail();
        $tramo = $this->tramo('240428', 1);

        $this->put(route('vacaciones.tramos.cambiar', $periodo), ['tramos' => [$tramo->id => ['inicio' => '2026-02-09', 'fin' => '2026-02-13']]])->assertSessionHasNoErrors();
        $this->assertSame('2026-02-09', $tramo->fresh()->inicio->toDateString());
        $this->assertSame('ajustado', $tramo->fresh()->origen);

        $this->actingAsTwoFactorVerified($this->usuario('Estación', $this->morelos));
        $beto = $this->tramo('241300', 2);
        $this->put(route('vacaciones.tramos.cambiar', $periodo), ['tramos' => [$beto->id => ['inicio' => '2026-04-20', 'fin' => '2026-04-24']], 'motivo' => 'Motivo'])->assertSessionHasNoErrors();
        $lote = VacacionSolicitud::query()->firstOrFail()->lote;

        $this->actingAsTwoFactorVerified($this->usuario('Edificio'));
        $this->post(route('vacaciones.lotes.rechazar', $lote))->assertInvalid('comentario');
        $this->post(route('vacaciones.lotes.rechazar', $lote), ['comentario' => 'No hay cobertura'])->assertSessionHasNoErrors();

        $this->assertSame('2026-04-13', $beto->fresh()->inicio->toDateString());
        $this->assertSame(VacacionSolicitud::RECHAZADA, VacacionSolicitud::query()->firstOrFail()->estado);
    }

    public function test_republishing_keeps_adjustments_and_cancels_pending_requests(): void
    {
        $this->publicar();
        $periodo = VacacionPeriodo::query()->firstOrFail();
        $tramo = $this->tramo('240428', 1);
        $this->put(route('vacaciones.tramos.cambiar', $periodo), ['tramos' => [$tramo->id => ['inicio' => '2026-02-09', 'fin' => '2026-02-13']]]);

        $this->actingAsTwoFactorVerified($this->usuario('Estación', $this->morelos));
        $beto = $this->tramo('241300', 2);
        $this->put(route('vacaciones.tramos.cambiar', $periodo), ['tramos' => [$beto->id => ['inicio' => '2026-04-20', 'fin' => '2026-04-24']], 'motivo' => 'M']);

        $hojas = $this->rol();
        $hojas['TULUM'] = $this->hoja([[240428, 'Carla Tulum', 22, [['2026-02-02', '2026-02-06', 5], null, null, null]]]);
        $nuevo = $this->publicar($hojas);

        $this->assertSame(2, $nuevo->version);
        $this->assertSame('2026-02-09', $this->tramoDe($nuevo, '240428', 1)->inicio->toDateString());
        $this->assertSame(VacacionSolicitud::CANCELADA, VacacionSolicitud::query()->firstOrFail()->estado);
        $this->assertSame(VacacionPeriodo::REEMPLAZADO, $periodo->fresh()->estado);
    }

    public function test_edit_mode_and_requests_pages_render_for_both_roles(): void
    {
        $this->publicar();
        $periodo = VacacionPeriodo::query()->firstOrFail();
        $beto = $this->tramo('241300', 2);

        $this->actingAsTwoFactorVerified($this->usuario('Estación', $this->morelos));
        $this->get(route('vacaciones.index', ['editar' => 1]))->assertOk()->assertSee('tramos['.$beto->id.'][inicio]', false)->assertSee('Motivo del cambio');
        $this->put(route('vacaciones.tramos.cambiar', $periodo), ['tramos' => [$beto->id => ['inicio' => '2026-04-20', 'fin' => '2026-04-24']], 'motivo' => 'Cobertura'])->assertSessionHasNoErrors();
        $this->get(route('vacaciones.solicitudes.index'))->assertOk()->assertSee('Cobertura')->assertSee('Cancelar envío');

        $this->actingAsTwoFactorVerified($this->usuario('Edificio'));
        $this->get(route('vacaciones.index', ['editar' => 1]))->assertOk()->assertSee('Guardar cambios')->assertSee('Cambio pendiente');
        $this->get(route('vacaciones.solicitudes.index'))->assertOk()->assertSee('Autorizar todo el envío');
    }

    public function test_person_already_over_granted_days_can_still_move_dates_but_not_grow(): void
    {
        $hojas = $this->rol();
        $hojas['TULUM'] = $this->hoja([[240428, 'Carla Tulum', 5, [['2026-02-02', '2026-02-09', 6], null, null, null]]]);
        $periodo = $this->publicar($hojas);
        $uno = $this->tramo('240428', 1);

        $this->put(route('vacaciones.tramos.cambiar', $periodo), ['tramos' => [$uno->id => ['inicio' => '2026-02-09', 'fin' => '2026-02-16']]])->assertSessionHasNoErrors();
        $this->put(route('vacaciones.tramos.cambiar', $periodo), ['tramos' => [$uno->id => ['inicio' => '2026-02-09', 'fin' => '2026-02-18']]])->assertInvalid('tramos');
    }

    public function test_zona_downloads_the_consolidated_workbook(): void
    {
        $periodo = $this->publicar();

        $respuesta = $this->get(route('vacaciones.consolidado.descargar', $periodo));
        $respuesta->assertOk();

        $libro = IOFactory::load($respuesta->baseResponse->getFile()->getPathname());
        $this->assertSame(['GENERAL', 'Puerto Morelos', 'Tulum', 'CAMBIOS'], $libro->getSheetNames());
        $this->assertSame('Ana Morelos', $libro->getSheetByName('GENERAL')->getCell('B2')->getValue());
    }

    private function tramoDe(VacacionPeriodo $periodo, string $empleado, int $trimestre): VacacionTramo
    {
        return VacacionTramo::query()->where('periodo_id', $periodo->id)->whereHas('persona', fn ($q) => $q->where('no_empleado', $empleado))->where('trimestre', $trimestre)->firstOrFail();
    }
}
