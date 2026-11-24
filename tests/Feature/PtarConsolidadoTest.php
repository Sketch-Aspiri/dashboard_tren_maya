<?php

namespace Tests\Feature;

use App\Models\Estacion;
use App\Models\PtarHallazgo;
use App\Models\PtarPlanta;
use App\Models\PtarRegistro;
use App\Models\User;
use App\Services\Ptar\PtarService;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Excel mensual de las PTAR y recordatorio mensual a las estaciones pendientes.
 */
class PtarConsolidadoTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private Estacion $tulum;

    private Estacion $bacalar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->bacalar = Estacion::factory()->create(['nombre' => 'Bacalar', 'codigo' => '29', 'orden' => 1]);
        $this->tulum = Estacion::factory()->create(['nombre' => 'Tulum', 'codigo' => '25', 'orden' => 8]);
    }

    private function con(string $rol, array $attrs = []): User
    {
        $user = User::factory()->create($attrs);
        $user->assignRole($rol);
        $this->actingAsTwoFactorVerified($user);

        return $user;
    }

    private function registroTulum(): PtarRegistro
    {
        $registro = PtarRegistro::query()->create([
            'estacion_id' => $this->tulum->id, 'anio' => 2026, 'mes' => 11, 'modo' => 'manual', 'modo_detalle' => 'Sopladores en manual',
            'observaciones_equipos' => 'Variador dañado', 'observaciones_generales' => 'Opera ACQUASU',
        ]);
        $registro->equipos()->create(['equipo' => 'sopladores', 'estatus' => 'fuera_servicio']);
        $registro->actividades()->create(['actividad' => 'Cárcamo', 'ultima_fecha' => '2026-10-05', 'nota' => null]);
        $registro->actividades()->create(['actividad' => 'Tolva', 'ultima_fecha' => null, 'nota' => 'N/A']);

        return $registro;
    }

    public function test_zone_downloads_the_monthly_excel_with_a_block_per_plant_and_the_open_findings(): void
    {
        PtarPlanta::sincronizar();
        $this->registroTulum();
        $planta = PtarPlanta::query()->where('estacion_id', $this->tulum->id)->firstOrFail();
        PtarHallazgo::query()->create(['planta_id' => $planta->id, 'descripcion' => 'Variador dañado', 'propuesta' => 'Reparar', 'prioridad' => 'alta']);
        PtarHallazgo::query()->create(['planta_id' => $planta->id, 'descripcion' => 'Ya atendido', 'estado' => 'cerrado']);
        $this->con('Jefe de Zona');

        $respuesta = $this->get(route('ptar.descargar', ['anio' => 2026, 'mes' => 11]));

        $respuesta->assertOk()->assertDownload('ESTATUS_PTAR_2026_11.xlsx');
        $archivo = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        file_put_contents($archivo, $respuesta->streamedContent());
        $libro = IOFactory::load($archivo);
        @unlink($archivo);

        $hoja = $libro->getSheet(0);
        $this->assertSame('Noviembre 2026', $hoja->getTitle());
        $filas = collect(range(4, 13))->mapWithKeys(fn ($f) => [$f => $hoja->getCell('C'.$f)->getValue()]);
        $inicio = $filas->search('Tulum');
        $this->assertSame('Manual', $hoja->getCell('D'.$inicio)->getValue());
        $this->assertSame('Opera ACQUASU', $hoja->getCell('L'.$inicio)->getValue());
        $this->assertSame('Con fallas', $hoja->getCell('O'.$inicio)->getValue());
        $this->assertSame('Sopladores', $hoja->getCell('G'.($inicio + 2))->getValue());
        $this->assertSame('Fuera de servicio', $hoja->getCell('H'.($inicio + 2))->getValue());
        $this->assertSame('Cárcamo', $hoja->getCell('J'.$inicio)->getValue());
        $this->assertSame('05/10/2026', $hoja->getCell('K'.$inicio)->getValue());
        $this->assertSame('N/A', $hoja->getCell('K'.($inicio + 1))->getValue());
        $this->assertSame('Sin captura', $hoja->getCell('N'.($inicio === 4 ? 9 : 4))->getValue());

        $hallazgos = $libro->getSheet(1);
        $this->assertSame('Hallazgos abiertos', $hallazgos->getTitle());
        $this->assertSame('Variador dañado', $hallazgos->getCell('F2')->getValue());
        $this->assertNull($hallazgos->getCell('F3')->getValue());
    }

    public function test_user_text_that_starts_with_equals_is_written_as_text_not_as_a_formula(): void
    {
        PtarPlanta::sincronizar();
        $registro = $this->registroTulum();
        $registro->update(['observaciones_generales' => '=HYPERLINK("http://malo.test","clic")', 'modo_detalle' => '=1+1']);
        $registro->actividades()->where('actividad', 'Tolva')->update(['nota' => '@SUM(1)']);
        $this->con('Jefe de Zona');

        $respuesta = $this->get(route('ptar.descargar', ['anio' => 2026, 'mes' => 11]));
        $archivo = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        file_put_contents($archivo, $respuesta->streamedContent());
        $hoja = IOFactory::load($archivo)->getSheet(0);
        @unlink($archivo);

        $inicio = collect(range(4, 13))->first(fn ($f) => $hoja->getCell('C'.$f)->getValue() === 'Tulum');
        foreach (['L', 'E'] as $columna) {
            $celda = $hoja->getCell($columna.$inicio);
            $this->assertSame('s', $celda->getDataType());
            $this->assertStringStartsWith('=', (string) $celda->getValue());
        }
    }

    public function test_only_the_zone_downloads_the_excel(): void
    {
        $this->con('Estación', ['estacion_id' => $this->tulum->id]);

        $this->get(route('ptar.descargar'))->assertForbidden();
    }

    public function test_reminder_goes_once_per_station_and_month_to_those_not_sent(): void
    {
        Queue::fake();
        config(['ecosistema.puente.secreto_salida' => 'secreto-dashboard-a-tickets-de-prueba-0123456789', 'ecosistema.puente.url' => 'https://tickets.ejemplo.test']);
        Cache::flush();
        $hoy = CarbonImmutable::now('America/Cancun');
        PtarRegistro::query()->create(['estacion_id' => $this->bacalar->id, 'anio' => $hoy->year, 'mes' => $hoy->month, 'estado' => 'enviada']);
        $cuenta = User::factory()->create(['estacion_id' => $this->tulum->id]);
        $cuenta->assignRole('Estación');

        $this->artisan('ptar:recordar-pendientes')->expectsOutputToContain('1 estación(es) con recordatorio.')->assertSuccessful();
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $cuenta->id]);
        $this->assertSame(1, $cuenta->notifications()->count());

        $this->artisan('ptar:recordar-pendientes')->expectsOutputToContain('0 estación(es) con recordatorio.')->assertSuccessful();
        $this->assertSame(1, $cuenta->notifications()->count());
    }

    public function test_reminder_does_nothing_without_the_bridge(): void
    {
        config(['ecosistema.puente.secreto_salida' => null]);

        $this->artisan('ptar:recordar-pendientes')->expectsOutputToContain('no está configurado')->assertSuccessful();
        $this->assertSame(0, app(PtarService::class)->tablero(2026, 11)->where('registro', '!=', null)->count());
    }
}
