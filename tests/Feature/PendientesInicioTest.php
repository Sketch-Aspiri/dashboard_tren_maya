<?php

namespace Tests\Feature;

use App\Enums\EmpleadoEstatus;
use App\Models\AfluenciaDiaria;
use App\Models\AguaRegistro;
use App\Models\Empleado;
use App\Models\Estacion;
use App\Models\PtarPlanta;
use App\Models\PtarRegistro;
use App\Models\SolicitudInsumo;
use App\Models\SolicitudPapeleria;
use App\Models\User;
use App\Services\Pendientes\PendientesEstacion;
use App\Services\Pendientes\PendientesZona;
use Carbon\CarbonImmutable;
use Database\Seeders\EquipoSeeder;
use Database\Seeders\MenuSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/** Inicio de Estación ("Mis pendientes") e inicio de Zona (bandeja "Por revisar"). */
class PendientesInicioTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private Estacion $tulum;

    private Estacion $bacalar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00', 'America/Cancun'));
        $this->seed(RoleSeeder::class);
        $this->tulum = Estacion::factory()->create(['nombre' => 'Tulum', 'is_operativa' => true, 'orden' => 1]);
        $this->bacalar = Estacion::factory()->create(['nombre' => 'Bacalar', 'is_operativa' => true, 'orden' => 2]);
        Empleado::factory()->create(['estacion_id' => $this->tulum->id, 'estatus' => EmpleadoEstatus::Activo->value]);
    }

    private function usuario(string $rol, array $atributos = []): User
    {
        $usuario = User::factory()->create($atributos);
        $usuario->assignRole($rol);
        $this->actingAsTwoFactorVerified($usuario);

        return $usuario;
    }

    private function estacion(array $atributos = []): User
    {
        return $this->usuario('Estación', ['estacion_id' => $this->tulum->id] + $atributos);
    }

    /** @return array<string, array<string, mixed>> */
    private function pendientesDe(User $usuario): array
    {
        return app(PendientesEstacion::class)->para($usuario)->keyBy('clave')->all();
    }

    public function test_station_sees_every_module_it_has_to_capture(): void
    {
        PtarPlanta::query()->create(['estacion_id' => $this->tulum->id]);
        $usuario = $this->estacion();

        $pendientes = $this->pendientesDe($usuario);

        $this->assertSame(['asistencia', 'afluencia', 'insumos', 'agua', 'ptar'], array_keys($pendientes));
        $this->assertSame('pendiente', $pendientes['asistencia']['estado']);
        $this->assertStringContainsString('9', $pendientes['afluencia']['detalle']);
        // El día 10 los insumos todavía son los de noviembre.
        $this->assertSame(route('insumos.index', ['anio' => 2026, 'mes' => 11]), $pendientes['insumos']['href']);

        $this->get(route('dashboard'))->assertOk()
            ->assertSee('Mis pendientes')
            ->assertSee('Captura de asistencia de hoy')
            ->assertSee('Flujo de pasajeros')
            ->assertSee('Solicitud de insumos')
            ->assertSee('Registro de agua')
            ->assertSee('Reporte de Estatus PTAR');
    }

    public function test_station_without_plant_does_not_see_ptar(): void
    {
        $this->assertArrayNotHasKey('ptar', $this->pendientesDe($this->estacion()));
    }

    public function test_state_follows_each_record_and_returned_ones_go_first(): void
    {
        AguaRegistro::query()->create(['estacion_id' => $this->tulum->id, 'anio' => 2026, 'mes' => 11, 'estado' => AguaRegistro::ENVIADA]);
        SolicitudInsumo::factory()->create(['estacion_id' => $this->tulum->id, 'anio' => 2026, 'mes' => 11, 'estado' => SolicitudInsumo::OBSERVADA]);
        foreach (range(1, 9) as $dia) {
            AfluenciaDiaria::query()->create(['estacion_id' => $this->tulum->id, 'fecha' => sprintf('2026-11-%02d', $dia), 'abordan' => 1, 'boletos' => 1]);
        }
        $usuario = $this->estacion();

        $lista = app(PendientesEstacion::class)->para($usuario);

        $this->assertSame('insumos', $lista->first()['clave']);
        $this->assertSame('alerta', $lista->first()['estado']);
        $this->assertSame('revision', $lista->firstWhere('clave', 'agua')['estado']);
        $this->assertSame('listo', $lista->firstWhere('clave', 'afluencia')['estado']);
    }

    public function test_returned_records_of_other_months_and_stationery_are_listed(): void
    {
        $papeleria = SolicitudPapeleria::factory()->create(['estacion_id' => $this->tulum->id, 'anio' => 2026, 'mes' => 10, 'estado' => SolicitudPapeleria::OBSERVADA]);
        $agua = AguaRegistro::query()->create(['estacion_id' => $this->tulum->id, 'anio' => 2026, 'mes' => 10, 'estado' => AguaRegistro::OBSERVADA]);

        $enlaces = app(PendientesEstacion::class)->para($this->estacion())->pluck('href')->all();

        $this->assertContains(route('papeleria.show', $papeleria), $enlaces);
        $this->assertContains(route('agua.show', $agua), $enlaces);
    }

    public function test_records_of_another_station_are_never_listed(): void
    {
        AguaRegistro::query()->create(['estacion_id' => $this->bacalar->id, 'anio' => 2026, 'mes' => 10, 'estado' => AguaRegistro::OBSERVADA]);

        $this->assertSame([], app(PendientesEstacion::class)->para($this->estacion())->where('estado', 'alerta')->all());
    }

    public function test_first_day_of_month_checks_the_previous_month(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-01 12:00', 'America/Cancun'));

        $fila = $this->pendientesDe($this->estacion())['afluencia'];

        $this->assertSame('pendiente', $fila['estado']);
        $this->assertSame(route('afluencia.estacion', ['estacion' => $this->tulum->id, 'anio' => 2026, 'mes' => 11]), $fila['href']);
    }

    public function test_strict_areas_limit_the_list_to_the_account_area(): void
    {
        $this->seed(MenuSeeder::class);
        $this->seed(EquipoSeeder::class);
        config(['equipos.estrictas' => true]);

        $this->assertSame(['asistencia'], array_keys($this->pendientesDe($this->estacion(['area' => 'rh']))));
    }

    public function test_zone_inbox_groups_what_waits_for_review(): void
    {
        SolicitudInsumo::factory()->create(['estacion_id' => $this->tulum->id, 'anio' => 2026, 'mes' => 12, 'estado' => SolicitudInsumo::ENVIADA]);
        SolicitudInsumo::factory()->create(['estacion_id' => $this->bacalar->id, 'anio' => 2026, 'mes' => 12, 'estado' => SolicitudInsumo::ENVIADA]);
        AguaRegistro::query()->create(['estacion_id' => $this->tulum->id, 'anio' => 2026, 'mes' => 10, 'estado' => AguaRegistro::ENVIADA]);
        PtarRegistro::query()->create(['estacion_id' => $this->tulum->id, 'anio' => 2026, 'mes' => 11, 'estado' => PtarRegistro::ENVIADA]);
        SolicitudPapeleria::factory()->create(['estacion_id' => $this->tulum->id, 'anio' => 2026, 'mes' => 11, 'estado' => SolicitudPapeleria::BORRADOR]);
        $usuario = $this->usuario('Jefe de Zona');

        $bandeja = app(PendientesZona::class)->para($usuario);

        $this->assertSame(['insumos', 'agua', 'ptar'], $bandeja->pluck('clave')->all());
        $this->assertSame(2, $bandeja->firstWhere('clave', 'insumos')['cantidad']);
        $this->assertSame(route('agua.index', ['anio' => 2026, 'mes' => 10]), $bandeja->firstWhere('clave', 'agua')['href']);

        $this->get(route('dashboard'))->assertOk()
            ->assertSee('Por revisar')
            ->assertSee(route('ptar.index', ['anio' => 2026, 'mes' => 11]))
            ->assertDontSee('Secciones con información')
            ->assertDontSee('Registros y documentos');
    }

    public function test_zone_inbox_counts_one_vacation_submission_once(): void
    {
        $periodo = DB::table('vacacion_periodos')->insertGetId(['anio' => 2026, 'version' => 1, 'estado' => 'vigente']);
        $lote = (string) Str::uuid();
        foreach ([1, 2] as $trimestre) {
            DB::table('vacacion_solicitudes')->insert([
                'periodo_id' => $periodo, 'lote' => $lote, 'estacion_id' => $this->tulum->id, 'no_empleado' => 'E1', 'nombre' => 'Persona',
                'trimestre' => $trimestre, 'motivo' => 'x', 'estado' => 'pendiente',
            ]);
        }

        $fila = app(PendientesZona::class)->para($this->usuario('Administrador'))->firstWhere('clave', 'vacaciones');

        $this->assertSame(1, $fila['cantidad']);
        $this->assertSame(route('vacaciones.solicitudes.index'), $fila['href']);
    }

    public function test_zone_inbox_says_when_there_is_nothing_to_review(): void
    {
        $this->usuario('Jefe de Zona');

        $this->get(route('dashboard'))->assertOk()->assertSee('No hay nada por revisar');
    }
}
