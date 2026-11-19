<?php

namespace Tests\Feature;

use App\Models\Estacion;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class UserManagementFiltersTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $admin = User::factory()->create(['name' => 'Zeta Admin']);
        $admin->assignRole('Administrador');
        $this->actingAsTwoFactorVerified($admin);
    }

    private function estacion(string $nombre, string $rol = 'Estación', ?string $area = null, ?string $persona = null): User
    {
        $estacion = Estacion::query()->where('nombre', $nombre)->first() ?? Estacion::factory()->create(['nombre' => $nombre]);
        $user = User::factory()->create(['name' => $persona ?? "Cuenta $nombre", 'estacion_id' => $estacion->id, 'area' => $area]);
        $user->assignRole($rol);

        return $user;
    }

    public function test_filters_by_account_type(): void
    {
        $this->estacion('Tulum');
        $jefe = User::factory()->create(['name' => 'Jefe Uno']);
        $jefe->assignRole('Jefe de Zona');

        $this->get(route('usuarios.index', ['rol' => 'Jefe de Zona']))
            ->assertOk()->assertSee('Jefe Uno')->assertDontSee('Cuenta Tulum');
    }

    public function test_filters_by_station_and_area(): void
    {
        $this->estacion('Tulum', area: 'rh', persona: 'Ana RH Tulum');
        $this->estacion('Tulum', area: 'rm', persona: 'Beto RM Tulum');
        $this->estacion('Limones', area: 'rh', persona: 'Cora RH Limones');
        $tulum = Estacion::query()->where('nombre', 'Tulum')->value('id');

        $this->get(route('usuarios.index', ['estacion' => $tulum]))
            ->assertSee('Ana RH Tulum')->assertSee('Beto RM Tulum')->assertDontSee('Cora RH Limones');

        $this->get(route('usuarios.index', ['estacion' => $tulum, 'area' => 'rm']))
            ->assertSee('Beto RM Tulum')->assertDontSee('Ana RH Tulum');

        $this->get(route('usuarios.index', ['area' => 'sin']))
            ->assertSee('Zeta Admin')->assertDontSee('Ana RH Tulum');
    }

    public function test_sorts_by_station_and_ignores_invalid_params(): void
    {
        $this->estacion('Tulum', persona: 'Persona Tulum');
        $this->estacion('Limones', persona: 'Persona Limones');
        $primera = Estacion::query()->orderBy('orden')->value('nombre');

        $this->get(route('usuarios.index', ['orden' => 'estacion', 'dir' => 'asc']))->assertOk();
        $this->get(route('usuarios.index', ['orden' => 'rol']))->assertOk();
        $this->get(route('usuarios.index', ['orden' => 'users.password; drop', 'dir' => 'x', 'q' => '%_']))->assertSessionHasErrors(['orden', 'dir']);
        $this->get(route('usuarios.index', ['rol' => ['a']]))->assertSessionHasErrors('rol');
        $this->assertNotNull($primera);
    }

    public function test_search_matches_name_or_email(): void
    {
        $this->estacion('Tulum', persona: 'Marina Buscada');
        $this->estacion('Limones', persona: 'Otra Persona');

        $this->get(route('usuarios.index', ['q' => 'Marina']))
            ->assertSee('Marina Buscada')->assertDontSee('Otra Persona');
    }
}
