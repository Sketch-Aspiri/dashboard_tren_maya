<?php

namespace Tests\Feature\Console;

use App\Models\Empleado;
use App\Models\Estacion;
use Database\Seeders\EstacionSeeder;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature coverage for app:link-empleados-estaciones. Uses dummy Empleado
 * factory rows (never real PII, per .claude/rules/testing.md).
 */
class LinkEmpleadosEstacionesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(EstacionSeeder::class);
    }

    public function test_it_links_a_high_confidence_plaza_actual_match(): void
    {
        $empleado = Empleado::factory()->create(['plaza_actual' => 'Chetumal', 'estacion_codigo' => null]);

        $this->artisan('app:link-empleados-estaciones')->assertExitCode(Command::SUCCESS);

        $chetumal = Estacion::query()->where('nombre', 'Chetumal')->firstOrFail();
        $this->assertSame($chetumal->id, $empleado->fresh()->estacion_id);
    }

    public function test_it_links_a_plaza_actual_with_a_hyphen_instead_of_a_space(): void
    {
        // Regression: the real roster has both "Tulum Aeropuerto" and
        // "Tulum-Aeropuerto" for the same station — the hyphen must be
        // treated as a word separator, not left as a literal character
        // that breaks the match against the catalog's "Tulum Aeropuerto".
        $empleado = Empleado::factory()->create(['plaza_actual' => 'Tulum-Aeropuerto', 'estacion_codigo' => null]);

        $this->artisan('app:link-empleados-estaciones')->assertExitCode(Command::SUCCESS);

        $tulumAeropuerto = Estacion::query()->where('nombre', 'Tulum Aeropuerto')->firstOrFail();
        $this->assertSame($tulumAeropuerto->id, $empleado->fresh()->estacion_id);
    }

    public function test_it_links_a_plaza_actual_with_a_trailing_parenthetical_and_leading_estacion_word(): void
    {
        $empleado = Empleado::factory()->create([
            'plaza_actual' => 'Estación Bacalar (Apoyo estación Bacalar)',
            'estacion_codigo' => null,
        ]);

        $this->artisan('app:link-empleados-estaciones')->assertExitCode(Command::SUCCESS);

        $bacalar = Estacion::query()->where('nombre', 'Bacalar')->firstOrFail();
        $this->assertSame($bacalar->id, $empleado->fresh()->estacion_id);
    }

    public function test_it_applies_the_corporativo_chetumal_override(): void
    {
        $empleado = Empleado::factory()->create(['plaza_actual' => 'Corporativo-Chetumal', 'estacion_codigo' => null]);

        $this->artisan('app:link-empleados-estaciones')->assertExitCode(Command::SUCCESS);

        $edificio = Estacion::query()->where('nombre', 'Edificio Zonal Este')->firstOrFail();
        $this->assertSame($edificio->id, $empleado->fresh()->estacion_id);
    }

    public function test_it_applies_the_limones_chacchoben_override(): void
    {
        $empleado = Empleado::factory()->create(['plaza_actual' => 'Limones-Chacchoben', 'estacion_codigo' => null]);

        $this->artisan('app:link-empleados-estaciones')->assertExitCode(Command::SUCCESS);

        $limones = Estacion::query()->where('nombre', 'Limones')->firstOrFail();
        $this->assertSame($limones->id, $empleado->fresh()->estacion_id);
    }

    public function test_it_leaves_comision_cgofp_unlinked(): void
    {
        $empleado = Empleado::factory()->create(['plaza_actual' => 'COMISIÓN CGOFP', 'estacion_codigo' => null]);

        $this->artisan('app:link-empleados-estaciones')->assertExitCode(Command::SUCCESS);

        $this->assertNull($empleado->fresh()->estacion_id);
    }

    public function test_it_falls_back_to_estacion_codigo_for_edificio_zonal_este(): void
    {
        $empleado = Empleado::factory()->create(['plaza_actual' => 'Personal de apoyo', 'estacion_codigo' => 'Edificio Zonal Este']);

        $this->artisan('app:link-empleados-estaciones')->assertExitCode(Command::SUCCESS);

        $edificio = Estacion::query()->where('nombre', 'Edificio Zonal Este')->firstOrFail();
        $this->assertSame($edificio->id, $empleado->fresh()->estacion_id);
    }

    public function test_dry_run_persists_nothing(): void
    {
        $empleado = Empleado::factory()->create(['plaza_actual' => 'Chetumal', 'estacion_codigo' => null]);

        $this->artisan('app:link-empleados-estaciones', ['--dry-run' => true])
            ->assertExitCode(Command::SUCCESS);

        $this->assertNull($empleado->fresh()->estacion_id);
    }

    public function test_it_does_not_touch_empleados_already_linked(): void
    {
        $bacalar = Estacion::query()->where('nombre', 'Bacalar')->firstOrFail();
        $chetumal = Estacion::query()->where('nombre', 'Chetumal')->firstOrFail();

        $empleado = Empleado::factory()->create([
            'plaza_actual' => 'Chetumal',
            'estacion_codigo' => null,
            'estacion_id' => $bacalar->id,
        ]);

        $this->artisan('app:link-empleados-estaciones')->assertExitCode(Command::SUCCESS);

        $this->assertSame($bacalar->id, $empleado->fresh()->estacion_id);
        $this->assertNotSame($chetumal->id, $empleado->fresh()->estacion_id);
    }
}
