<?php

namespace Tests\Feature;

use App\Enums\EmpleadoEstatus;
use App\Models\Empleado;
use App\Models\Estacion;
use App\Models\User;
use App\Notifications\AvisoDashboard;
use App\Services\Cumpleanos\CumpleanosService;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Cumpleaños: página para la Zona y las estaciones (solo día y mes) y aviso diario "hoy cumple años".
 */
class CumpleanosTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private Estacion $morelos;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->morelos = Estacion::factory()->create(['nombre' => 'Puerto Morelos']);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-12 09:00', 'America/Cancun'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function usuario(string $rol): User
    {
        $user = User::factory()->create($rol === 'Estación' ? ['estacion_id' => $this->morelos->id] : []);
        $user->assignRole($rol);

        return $user;
    }

    private function persona(string $nombre, ?string $nacimiento, string $estatus = 'activo'): Empleado
    {
        return Empleado::factory()->create(['nombre_completo' => $nombre, 'fecha_nacimiento' => $nacimiento, 'estatus' => $estatus, 'estacion_id' => $this->morelos->id]);
    }

    public function test_guest_is_redirected_and_unauthorized_roles_are_forbidden(): void
    {
        $this->get(route('cumpleanos.index'))->assertRedirect(route('login'));

        $this->actingAsTwoFactorVerified(User::factory()->create());
        $this->get(route('cumpleanos.index'))->assertForbidden();
    }

    public function test_zona_and_estacion_see_today_upcoming_and_the_month_without_year_or_age(): void
    {
        $this->persona('Ana Hoy', '1990-03-12');
        $this->persona('Beto Pronto', '1985-03-20');
        $this->persona('Carla Inactiva', '1980-03-12', EmpleadoEstatus::Vacante->value);
        $this->persona('Dora SinFecha', null);

        foreach (['Edificio', 'Estación'] as $rol) {
            $this->actingAsTwoFactorVerified($this->usuario($rol));
            $this->get(route('cumpleanos.index'))->assertOk()
                ->assertSee('Ana Hoy')->assertSee('Beto Pronto')->assertSee('20 de marzo')
                ->assertDontSee('Carla Inactiva')->assertDontSee('Dora SinFecha')
                ->assertDontSee('1990')->assertDontSee('1985');
        }
    }

    public function test_filters_validate_and_apply(): void
    {
        $this->persona('Ana Hoy', '1990-03-12');
        $this->actingAsTwoFactorVerified($this->usuario('Edificio'));

        $this->get(route('cumpleanos.index', ['mes' => 13]))->assertInvalid('mes');
        $this->get(route('cumpleanos.index', ['mes' => 5, 'q' => 'Ana']))->assertOk()->assertSee('Sin cumpleaños con ese filtro');
    }

    public function test_leap_day_birthday_is_celebrated_on_feb_28_in_common_years(): void
    {
        $this->persona('Bisiesta Luz', '2000-02-29');
        $servicio = app(CumpleanosService::class);

        $this->assertCount(1, $servicio->deHoy(CarbonImmutable::parse('2027-02-28')));
        $this->assertCount(0, $servicio->deHoy(CarbonImmutable::parse('2027-03-01')));
        $this->assertCount(0, $servicio->deHoy(CarbonImmutable::parse('2028-02-28')));
        $this->assertCount(1, $servicio->deHoy(CarbonImmutable::parse('2028-02-29')));
    }

    public function test_command_notifies_every_account_once_a_day(): void
    {
        Notification::fake();
        Cache::flush();
        $this->persona('Ana Hoy', '1990-03-12');
        $cuentas = collect(['Edificio', 'Estación', 'Jefe de Zona'])->map(fn ($rol) => $this->usuario($rol));

        $this->artisan('cumpleanos:avisar-hoy')->assertSuccessful();
        $this->artisan('cumpleanos:avisar-hoy')->assertSuccessful();

        Notification::assertSentToTimes($cuentas[0], AvisoDashboard::class, 1);
        Notification::assertSentTo($cuentas[1], AvisoDashboard::class, fn ($n) => $n->toArray($cuentas[1])['cuerpo'] === 'Hoy cumple años Ana Hoy.' && $n->toArray($cuentas[1])['modulo'] === 'cumpleanos');
        Notification::assertSentTo($cuentas[2], AvisoDashboard::class);
    }

    public function test_command_sends_nothing_when_nobody_has_a_birthday(): void
    {
        Notification::fake();
        Cache::flush();
        $this->persona('Beto Pronto', '1985-03-20');
        $this->usuario('Edificio');

        $this->artisan('cumpleanos:avisar-hoy')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_notice_text_fits_even_with_many_people(): void
    {
        Notification::fake();
        Cache::flush();

        foreach (range(1, 6) as $i) {
            $this->persona('Persona Con Un Nombre Bastante Largo Número '.$i.' Apellido', '1990-03-12');
        }

        $user = $this->usuario('Edificio');
        $this->artisan('cumpleanos:avisar-hoy')->assertSuccessful();

        Notification::assertSentTo($user, AvisoDashboard::class, function ($n) use ($user) {
            $datos = $n->toArray($user);

            return mb_strlen($datos['cuerpo']) <= 120 && str_contains($datos['cuerpo'], '4 más');
        });
    }

    public function test_command_is_scheduled_daily_at_8_cancun(): void
    {
        $evento = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'cumpleanos:avisar-hoy'));

        $this->assertNotNull($evento);
        $this->assertSame('0 8 * * *', $evento->expression);
        $this->assertSame('America/Cancun', (string) $evento->timezone);
    }
}
