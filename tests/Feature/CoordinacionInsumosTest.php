<?php

namespace Tests\Feature;

use App\Jobs\EnviarActividadTickets;
use App\Models\Estacion;
use App\Models\Proveedor;
use App\Models\SolicitudInsumo;
use App\Models\SolicitudInsumoPartida;
use App\Models\User;
use App\Services\SolicitudInsumoService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Feature coverage for the Coordinación cycle: registering the ANEXO sent
 * with its oficio number and applying the Excel the Coordinación returns
 * (final partida, removed and added lines, changed quantities and prices).
 * Dummy factory data only (.claude/rules/testing.md).
 */
class CoordinacionInsumosTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private Estacion $pmo;

    private Estacion $pca;

    private Estacion $eze;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->pmo = Estacion::factory()->create(['nombre' => 'Puerto Morelos', 'codigo' => '23', 'orden' => 2]);
        $this->pca = Estacion::factory()->create(['nombre' => 'Playa del Carmen', 'codigo' => '24', 'orden' => 3]);
        $this->eze = Estacion::factory()->create(['nombre' => 'Edificio Zonal Este', 'codigo' => '00', 'orden' => 1]);
    }

    private function userWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    private function solicitud(Estacion $estacion, string $estado, array $partidas = []): SolicitudInsumo
    {
        $solicitud = SolicitudInsumo::factory()->create(['estacion_id' => $estacion->id, 'anio' => 2026, 'mes' => 12, 'estado' => $estado]);

        foreach ($partidas as $i => [$descripcion, $cantidad, $precio]) {
            SolicitudInsumoPartida::factory()->create([
                'solicitud_insumo_id' => $solicitud->id, 'numero' => $i + 1, 'descripcion' => $descripcion,
                'cantidad' => $cantidad, 'precio_unitario' => $precio, 'partida_presupuestal' => '24901', 'proveedor' => 'Truper',
            ]);
        }

        return $solicitud;
    }

    /**
     * Estación 23 con tres partidas enviadas, 24 y 00 también enviadas.
     */
    private function escenario(): SolicitudInsumo
    {
        $pmo = $this->solicitud($this->pmo, SolicitudInsumo::ENVIADA_COORDINACION, [['Cable primario', 2, 100], ['Cinta aislante', 2, 10], ['Aceite', 1, 107]]);
        $this->solicitud($this->pca, SolicitudInsumo::ENVIADA_COORDINACION, [['Pintura', 1, 200]]);
        $this->solicitud($this->eze, SolicitudInsumo::ENVIADA_COORDINACION, [['Valvula', 1, 500]]);

        return $pmo;
    }

    /**
     * Excel devuelto: bloque 23 con cambios, bloque 24 sin renglones y sin bloque 00.
     */
    private function respuesta(): UploadedFile
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet()->setTitle('ZO_Cotización');

        $hoja->setCellValue('B7', '23_PUERTO MORELOS')->setCellValue('B8', 'Lic. Prueba, Gerencia');
        $filas = [
            [10, 1, 2, 'Pieza', 'Cable primario', 100, '24901', '26105', 'Justificación'],
            [11, 2, 3, 'Pieza', 'CINTA  aislante', 12, '24601', null, null],
            [12, 3, 4, 'Pieza', 'Guantes de nitrilo', 50, '24601', '24601', 'Nuevo renglón'],
        ];
        foreach ($filas as [$fila, $n, $cant, $unidad, $desc, $precio, $h, $i, $just]) {
            $hoja->setCellValue('B'.$fila, $n)->setCellValue('C'.$fila, $cant)->setCellValue('D'.$fila, $unidad)->setCellValue('E'.$fila, $desc)
                ->setCellValue('F'.$fila, $precio)->setCellValue('H'.$fila, $h)->setCellValue('I'.$fila, $i)->setCellValue('J'.$fila, $just);
        }
        $hoja->setCellValue('E13', 'Subtotal')->setCellValue('F13', 999);

        $hoja->setCellValue('B20', '24_PLAYA DEL CARMEN')->setCellValue('B22', 1)->setCellValue('E22', 'No realizaron solicitud a la fecha');

        $ruta = tempnam(sys_get_temp_dir(), 'resp').'.xlsx';
        (new Xlsx($libro))->save($ruta);

        return new UploadedFile($ruta, 'ANEXO_DEVUELTO.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function hoja(TestResponse $respuesta, string $nombre = 'ZO_Cotización'): Worksheet
    {
        $ruta = tempnam(sys_get_temp_dir(), 'coord').'.xlsx';
        file_put_contents($ruta, $respuesta->streamedContent());

        return IOFactory::load($ruta)->getSheetByName($nombre);
    }

    // --- Authentication / authorization ------------------------------------

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('insumos.coordinacion.index'))->assertRedirect(route('login'));
        $this->post(route('insumos.coordinacion.enviar'), [])->assertRedirect(route('login'));
        $this->post(route('insumos.coordinacion.respuesta'), [])->assertRedirect(route('login'));
    }

    public function test_only_jefatura_can_use_the_coordinacion_screens(): void
    {
        $estacion = Estacion::factory()->create();

        foreach (['Estación', 'Edificio'] as $rol) {
            $this->actingAsTwoFactorVerified($this->userWithRole($rol, ['estacion_id' => $rol === 'Estación' ? $estacion->id : null]));

            $this->get(route('insumos.coordinacion.index'))->assertForbidden();
            $this->post(route('insumos.coordinacion.enviar'), ['anio' => 2026, 'mes' => 12, 'oficio' => '1'])->assertForbidden();
            $this->post(route('insumos.coordinacion.respuesta'), ['anio' => 2026, 'mes' => 12, 'archivo' => $this->respuesta()])->assertForbidden();
        }

        foreach (['Jefe de Zona', 'Administrador'] as $rol) {
            $this->actingAsTwoFactorVerified($this->userWithRole($rol));

            $this->get(route('insumos.coordinacion.index', ['anio' => 2026, 'mes' => 12]))->assertOk();
        }
    }

    // --- Envío ------------------------------------------------------------------

    public function test_registering_the_envio_moves_only_approved_solicitudes_and_is_logged(): void
    {
        $aprobada = $this->solicitud($this->pmo, SolicitudInsumo::APROBADA);
        $enviada = $this->solicitud($this->pca, SolicitudInsumo::ENVIADA);
        $borrador = $this->solicitud($this->eze, SolicitudInsumo::BORRADOR);
        $this->actingAsTwoFactorVerified($usuario = $this->userWithRole('Jefe de Zona'));

        $this->post(route('insumos.coordinacion.enviar'), ['anio' => 2026, 'mes' => 12, 'oficio' => '1918'])
            ->assertRedirect()->assertSessionHas('status');

        $aprobada->refresh();
        $this->assertSame('enviada_coordinacion', $aprobada->estado);
        $this->assertSame('1918', $aprobada->oficio_numero);
        $this->assertNotNull($aprobada->enviada_coordinacion_at);
        $this->assertSame('enviada', $enviada->fresh()->estado);
        $this->assertSame('borrador', $borrador->fresh()->estado);

        $this->assertTrue(Activity::query()->where('log_name', 'solicitud_insumo')->where('description', 'envio a coordinacion registrado')->where('causer_id', $usuario->id)->exists());
    }

    public function test_envio_needs_an_approved_solicitud_and_a_safe_oficio(): void
    {
        $this->solicitud($this->pmo, SolicitudInsumo::ENVIADA);
        $this->actingAsTwoFactorVerified($this->userWithRole('Administrador'));

        $this->post(route('insumos.coordinacion.enviar'), ['anio' => 2026, 'mes' => 12, 'oficio' => '1918'])->assertSessionHasErrors('oficio');
        $this->post(route('insumos.coordinacion.enviar'), ['anio' => 2026, 'mes' => 12, 'oficio' => '../x'])->assertInvalid('oficio');
        $this->post(route('insumos.coordinacion.enviar'), ['anio' => 2026, 'mes' => 12, 'oficio' => ''])->assertInvalid('oficio');
        $this->post(route('insumos.coordinacion.enviar'), ['anio' => 2026, 'mes' => 13, 'oficio' => '1'])->assertInvalid('mes');
    }

    // --- Respuesta ---------------------------------------------------------------

    public function test_the_returned_excel_sets_final_partida_removes_adds_and_changes(): void
    {
        $pmo = $this->escenario();
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->post(route('insumos.coordinacion.respuesta'), ['anio' => 2026, 'mes' => 12, 'archivo' => $this->respuesta()])
            ->assertRedirect()->assertSessionHas('resumen_coordinacion');

        $pmo->refresh();
        $this->assertSame('devuelta', $pmo->estado);
        $this->assertNotNull($pmo->devuelta_at);

        $cable = $pmo->partidas()->where('descripcion', 'Cable primario')->firstOrFail();
        $this->assertSame('26105', $cable->partida_final);          // columna I
        $this->assertFalse($cable->eliminada_por_coordinacion);

        $cinta = $pmo->partidas()->where('descripcion', 'Cinta aislante')->firstOrFail();
        $this->assertSame('24601', $cinta->partida_final);          // sin I, toma la H
        $this->assertSame('3.00', $cinta->cantidad);                 // 2 -> 3
        $this->assertSame('12.00', $cinta->precio_unitario);         // 10 -> 12

        $aceite = $pmo->partidas()->where('descripcion', 'Aceite')->firstOrFail();
        $this->assertTrue($aceite->eliminada_por_coordinacion);      // la Coordinación lo quitó

        $nuevo = $pmo->partidas()->where('descripcion', 'Guantes de nitrilo')->firstOrFail();
        $this->assertTrue($nuevo->agregada_por_coordinacion);
        $this->assertSame(4, $nuevo->numero);
        $this->assertSame('24601', $nuevo->partida_final);

        $resumen = session('resumen_coordinacion');
        $this->assertSame(1, $resumen['solicitudes']);
        $this->assertSame(['Puerto Morelos: Aceite'], $resumen['quitadas']);
        $this->assertSame(['Puerto Morelos: Guantes de nitrilo'], $resumen['agregadas']);
        $this->assertCount(2, $resumen['cambios']);
        $this->assertSame(['Playa del Carmen'], $resumen['sin_renglones']);
        $this->assertSame(['Edificio Zonal Este'], $resumen['sin_bloque']);
    }

    public function test_returning_from_coordinacion_asks_tickets_for_one_activity_per_returned_station(): void
    {
        Queue::fake();
        config([
            'ecosistema.puente.secreto_salida' => 'secreto-dashboard-a-tickets-de-prueba-0123456789',
            'ecosistema.puente.url' => 'https://tickets.ejemplo.test',
            'ecosistema.urls.dashboard' => 'https://dashboard.ejemplo.test',
            'ecosistema.actividades.eventos' => ['insumos_devuelta'],
        ]);
        $pmo = $this->escenario();
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->post(route('insumos.coordinacion.respuesta'), ['anio' => 2026, 'mes' => 12, 'archivo' => $this->respuesta()])->assertRedirect();

        // Solo la estación 23 recibió cambios (24 sin renglones y 00 sin bloque no se tocan).
        Queue::assertPushed(EnviarActividadTickets::class, 1);
        $job = Queue::pushed(EnviarActividadTickets::class)->first();
        $pmo->refresh();
        $this->assertSame('insumos_devuelta', $job->evento);
        $this->assertSame('dashboard:solicitud:'.$pmo->id.':devuelta:'.$pmo->devuelta_at->getTimestamp(), $job->ref);
        $this->assertSame('23', $job->estacion);
        $this->assertSame('Solicitud de insumos devuelta por Coordinación: Puerto Morelos', $job->titulo);
        $this->assertSame('https://dashboard.ejemplo.test/insumos/estaciones/'.$this->pmo->id, $job->url);
        // Sin montos ni descripciones de partidas en la petición.
        $this->assertStringNotContainsString('Cable', $job->descripcion);
        $this->assertStringNotContainsString('$', $job->descripcion);
    }

    public function test_stations_without_lines_or_without_block_are_not_touched(): void
    {
        $this->escenario();
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->post(route('insumos.coordinacion.respuesta'), ['anio' => 2026, 'mes' => 12, 'archivo' => $this->respuesta()]);

        foreach ([$this->pca, $this->eze] as $estacion) {
            $solicitud = SolicitudInsumo::query()->where('estacion_id', $estacion->id)->firstOrFail();
            $this->assertSame('enviada_coordinacion', $solicitud->estado);
            $this->assertSame(0, $solicitud->partidas()->where('eliminada_por_coordinacion', true)->count());
        }
    }

    /**
     * Caso real (septiembre 2026): los títulos de bloque vienen escritos de
     * muchas formas y un título que no se reconoce nunca debe pegar sus
     * renglones a la estación anterior.
     */
    public function test_irregular_block_titles_are_recognised_and_never_merged_into_the_previous_station(): void
    {
        $this->solicitud($this->pmo, SolicitudInsumo::ENVIADA_COORDINACION, [['Cable primario', 2, 100]]);
        $this->solicitud($this->pca, SolicitudInsumo::ENVIADA_COORDINACION, [['Pintura', 1, 200]]);
        $this->solicitud($this->eze, SolicitudInsumo::ENVIADA_COORDINACION, [['Valvula', 1, 500]]);
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet()->setTitle('ZO_Cotización');
        // Título con código y espacios, sin código (por nombre), con nombre distinto y uno que nadie reconoce.
        $bloques = [
            [7, '23 PUERTO MORELOS', 'Cable primario', 24901],
            [17, 'Playa del Carmen', 'Pintura', 26105],
            [27, 'Bloque que nadie reconoce', 'Renglon ajeno que no debe pegarse a Playa', 29101],
            [37, '00 _  Edificio Zonal Este', 'Valvula', 24801],
        ];
        foreach ($bloques as [$fila, $titulo, $descripcion, $partida]) {
            $hoja->setCellValue('B'.$fila, $titulo)->setCellValue('B'.($fila + 1), 'Lic. Prueba, Gerencia')->setCellValue('B'.($fila + 2), 'N°');
            $hoja->setCellValue('B'.($fila + 3), 1)->setCellValue('C'.($fila + 3), 1)->setCellValue('D'.($fila + 3), 'Pieza')->setCellValue('E'.($fila + 3), $descripcion)->setCellValue('F'.($fila + 3), 10)->setCellValue('I'.($fila + 3), $partida);
            $hoja->setCellValue('E'.($fila + 4), 'Subtotal');
        }
        $ruta = tempnam(sys_get_temp_dir(), 'irreg').'.xlsx';
        (new Xlsx($libro))->save($ruta);

        $this->post(route('insumos.coordinacion.respuesta'), ['anio' => 2026, 'mes' => 12, 'archivo' => new UploadedFile($ruta, 'irregular.xlsx', null, null, true)]);
        $resumen = session('resumen_coordinacion');

        $this->assertSame(3, $resumen['solicitudes']);                                   // las tres estaciones reconocidas
        $this->assertSame(['Bloque que nadie reconoce (no se reconoció la estación)'], $resumen['sin_solicitud']);
        $this->assertSame([], $resumen['agregadas']);                                    // nada se agregó a otra estación
        $this->assertSame(1, SolicitudInsumo::query()->where('estacion_id', $this->pca->id)->firstOrFail()->partidas()->count());
        $this->assertSame('26105', SolicitudInsumo::query()->where('estacion_id', $this->pca->id)->firstOrFail()->partidas()->first()->partida_final);
        $this->assertSame('24801', SolicitudInsumo::query()->where('estacion_id', $this->eze->id)->firstOrFail()->partidas()->first()->partida_final);
    }

    public function test_loading_the_same_file_twice_does_not_duplicate_lines(): void
    {
        $pmo = $this->escenario();
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->post(route('insumos.coordinacion.respuesta'), ['anio' => 2026, 'mes' => 12, 'archivo' => $this->respuesta()]);
        $this->post(route('insumos.coordinacion.respuesta'), ['anio' => 2026, 'mes' => 12, 'archivo' => $this->respuesta()]);

        $this->assertSame(1, $pmo->partidas()->where('descripcion', 'Guantes de nitrilo')->count());
        $this->assertSame(4, $pmo->partidas()->count());
        $this->assertSame(['devuelta'], [$pmo->fresh()->estado]);
        $this->assertSame([], session('resumen_coordinacion')['agregadas']);
    }

    public function test_solicitudes_not_sent_to_the_coordinacion_are_ignored(): void
    {
        $aprobada = $this->solicitud($this->pmo, SolicitudInsumo::APROBADA, [['Cable primario', 2, 100]]);
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->post(route('insumos.coordinacion.respuesta'), ['anio' => 2026, 'mes' => 12, 'archivo' => $this->respuesta()]);

        $this->assertSame('aprobada', $aprobada->fresh()->estado);
        $this->assertNull($aprobada->partidas()->first()->partida_final);
        $this->assertSame(['23_PUERTO MORELOS', '24_PLAYA DEL CARMEN'], session('resumen_coordinacion')['sin_solicitud']); // ningún bloque tiene solicitud enviada
    }

    public function test_invalid_files_are_rejected(): void
    {
        $this->escenario();
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->post(route('insumos.coordinacion.respuesta'), ['anio' => 2026, 'mes' => 12, 'archivo' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])->assertInvalid('archivo');
        $this->post(route('insumos.coordinacion.respuesta'), ['anio' => 2026, 'mes' => 12])->assertInvalid('archivo');
        $this->post(route('insumos.coordinacion.respuesta'), ['anio' => 2026, 'mes' => 12, 'archivo' => UploadedFile::fake()->createWithContent('roto.xlsx', 'no es excel')])->assertSessionHasErrors('archivo');

        $libro = new Spreadsheet;
        $libro->getActiveSheet()->setCellValue('B2', 'sin bloques')->setCellValue('E2', 'nada');
        $ruta = tempnam(sys_get_temp_dir(), 'vacio').'.xlsx';
        (new Xlsx($libro))->save($ruta);
        $this->post(route('insumos.coordinacion.respuesta'), ['anio' => 2026, 'mes' => 12, 'archivo' => new UploadedFile($ruta, 'vacio.xlsx', null, null, true)])->assertSessionHasErrors('archivo');

        $this->assertSame(0, SolicitudInsumoPartida::query()->where('eliminada_por_coordinacion', true)->count());
    }

    // --- Efecto en el resto del módulo ----------------------------------------------

    public function test_consolidado_keeps_returned_solicitudes_but_drops_removed_lines_and_uses_the_final_partida(): void
    {
        $this->escenario();
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));
        $this->post(route('insumos.coordinacion.respuesta'), ['anio' => 2026, 'mes' => 12, 'archivo' => $this->respuesta()]);

        $hoja = $this->hoja($this->get(route('insumos.consolidado', ['anio' => 2026, 'mes' => 12, 'tipo' => 'anexo'])));
        $textos = collect($hoja->toArray())->flatten()->filter()->implode('|');

        // Orden del ANEXO: 23 primero. Renglones del bloque: Cable, Cinta, Guantes (Aceite quitado).
        $this->assertSame('23_PUERTO MORELOS', $hoja->getCell('B7')->getValue());
        $this->assertSame('Cable primario', $hoja->getCell('E10')->getValue());
        $this->assertSame('24901', $hoja->getCell('H10')->getValue());
        $this->assertSame('26105', $hoja->getCell('I10')->getValue());          // partida final de la Coordinación
        $this->assertSame('Guantes de nitrilo', $hoja->getCell('E12')->getValue());
        $this->assertStringNotContainsString('Aceite', $textos);
        $this->assertSame('=SUM(G10:G12)', $hoja->getCell('G13')->getValue());
    }

    public function test_show_marks_removed_and_added_lines_and_the_totals_skip_the_removed(): void
    {
        $pmo = $this->escenario();
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));
        $this->post(route('insumos.coordinacion.respuesta'), ['anio' => 2026, 'mes' => 12, 'archivo' => $this->respuesta()]);

        $this->get(route('insumos.show', $pmo))
            ->assertOk()
            ->assertSee('Devuelta por Coordinación')
            ->assertSee('quitada por la Coordinación')
            ->assertSee('agregada por la Coordinación')
            ->assertSee('26105');

        // Cable 2x100 + Cinta 3x12 + Guantes 4x50 = 436 (el Aceite quitado no cuenta)
        $totales = app(SolicitudInsumoService::class)->totales($pmo->fresh()->load('partidas'));
        $this->assertSame(436.0, $totales['subtotal']);
    }

    public function test_board_shows_the_coordinacion_button_and_the_new_states(): void
    {
        $this->escenario();
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->get(route('insumos.index', ['anio' => 2026, 'mes' => 12]))
            ->assertOk()
            ->assertSee('Coordinación')
            ->assertSee('Enviada a Coordinación');

        $this->actingAsTwoFactorVerified($this->userWithRole('Edificio'));
        $this->get(route('insumos.index', ['anio' => 2026, 'mes' => 12]))->assertOk()->assertDontSee(route('insumos.coordinacion.index'), false);
    }

    public function test_provider_can_be_marked_as_retaining_isr(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Administrador'));

        $this->post(route('insumos.proveedores.store'), ['nombre' => 'MIG Persona Fisica', 'iva' => '8', 'retiene_isr' => '1'])->assertRedirect();
        $proveedor = Proveedor::query()->firstOrFail();
        $this->assertTrue($proveedor->retiene_isr);

        $this->put(route('insumos.proveedores.update', $proveedor), ['iva' => '8'])->assertRedirect();
        $this->assertFalse($proveedor->fresh()->retiene_isr);
    }
}
