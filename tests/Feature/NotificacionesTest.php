<?php

namespace Tests\Feature;

use App\Jobs\EnviarAvisoTickets;
use App\Models\Estacion;
use App\Models\HorarioPeriodo;
use App\Models\User;
use App\Notifications\AvisoDashboard;
use App\Services\Ecosistema\AvisosEcosistema;
use App\Services\NotificationCenter;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Bandeja de notificaciones del Dashboard (campana): AvisosEcosistema guarda cada aviso en la bandeja local de
 * todas las cuentas destinatarias (vinculadas o no) y la vista solo muestra/abre/marca las propias.
 */
class NotificacionesTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function usuario(string $rol, array $atributos = []): User
    {
        $user = User::factory()->create($atributos);
        $user->assignRole($rol);

        return $user;
    }

    private function como(User $user): static
    {
        $this->actingAsTwoFactorVerified($user);

        return $this;
    }

    private function avisar(User $user, string $titulo = 'Horario publicado', string $modulo = 'horarios', string $ruta = '/horarios'): string
    {
        $user->notify(new AvisoDashboard($modulo, $titulo, 'Cuerpo de prueba.', $ruta));

        return $user->notifications()->latest()->first()->id;
    }

    // --- Guardado desde AvisosEcosistema -----------------------------------------------------------------------------------------

    public function test_horario_published_is_stored_for_every_station_account_even_unlinked_and_without_bridge(): void
    {
        Queue::fake();
        $estacion = Estacion::factory()->create(['nombre' => 'Tulum']);
        $vinculada = $this->usuario('Estación', ['estacion_id' => $estacion->id, 'sso_vinculado_at' => now()]);
        $sinVincular = $this->usuario('Estación', ['estacion_id' => $estacion->id, 'sso_vinculado_at' => null]);
        $otra = $this->usuario('Estación', ['estacion_id' => Estacion::factory()->create()->id]);

        $periodo = new HorarioPeriodo(['anio' => 2026, 'mes' => 12]);
        app(AvisosEcosistema::class)->horarioPublicado($estacion, $periodo);

        $this->assertSame(1, $vinculada->notifications()->count());
        $this->assertSame(1, $sinVincular->notifications()->count());
        $this->assertSame(0, $otra->notifications()->count());

        $datos = $vinculada->notifications()->first()->data;
        $this->assertSame('horarios', $datos['modulo']);
        $this->assertSame('Horario publicado', $datos['titulo']);
        $this->assertSame('/horarios', $datos['url']);
        // Puente apagado: nada se encola para Tickets.
        Queue::assertNothingPushed();
    }

    public function test_push_to_tickets_still_goes_only_to_linked_accounts(): void
    {
        Queue::fake();
        config([
            'ecosistema.puente.secreto_entrada' => 'secreto-tickets-a-dashboard-de-prueba-0123456789',
            'ecosistema.puente.secreto_salida' => 'secreto-dashboard-a-tickets-de-prueba-0123456789',
            'ecosistema.puente.url' => 'https://tickets.ejemplo.test',
            'ecosistema.urls.dashboard' => 'https://dashboard.ejemplo.test',
        ]);
        $estacion = Estacion::factory()->create();
        $vinculada = $this->usuario('Estación', ['estacion_id' => $estacion->id, 'email' => 'a@ejemplo.test', 'sso_vinculado_at' => now()]);
        $sinVincular = $this->usuario('Estación', ['estacion_id' => $estacion->id, 'email' => 'b@ejemplo.test', 'sso_vinculado_at' => null]);

        app(AvisosEcosistema::class)->asistenciaPendiente($estacion);

        Queue::assertPushed(EnviarAvisoTickets::class, fn (EnviarAvisoTickets $job) => $job->correos === ['a@ejemplo.test']);
        $this->assertSame(1, $vinculada->notifications()->count());
        $this->assertSame(1, $sinVincular->notifications()->count());
    }

    // --- Vista -------------------------------------------------------------------------------------------------------------------

    public function test_guest_cannot_see_notifications(): void
    {
        $this->get(route('notificaciones.index'))->assertRedirect(route('login'));
        $this->getJson(route('notificaciones.summary'))->assertUnauthorized();
    }

    public function test_user_sees_only_their_own_notifications(): void
    {
        $yo = $this->usuario('Jefe de Zona');
        $otro = $this->usuario('Jefe de Zona');
        $this->avisar($yo, 'Aviso mío');
        $this->avisar($otro, 'Aviso ajeno');

        $this->como($yo)->get(route('notificaciones.index'))
            ->assertOk()->assertSee('Aviso mío')->assertDontSee('Aviso ajeno');
    }

    public function test_filters_by_unread_and_module(): void
    {
        $yo = $this->usuario('Jefe de Zona');
        $leida = $this->avisar($yo, 'Ya leída', 'insumos');
        $this->avisar($yo, 'Sin leer horarios', 'horarios');
        $this->avisar($yo, 'Sin leer insumos', 'insumos');
        $yo->notifications()->findOrFail($leida)->markAsRead();

        $this->como($yo)->get(route('notificaciones.index', ['estado' => 'no-leidas']))
            ->assertSee('Sin leer horarios')->assertSee('Sin leer insumos')->assertDontSee('Ya leída');

        $this->como($yo)->get(route('notificaciones.index', ['modulo' => 'insumos']))
            ->assertSee('Ya leída')->assertSee('Sin leer insumos')->assertDontSee('Sin leer horarios');
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $yo = $this->usuario('Jefe de Zona');

        $this->como($yo)->get(route('notificaciones.index', ['modulo' => "x') or 1=1 --"]))
            ->assertSessionHasErrors('modulo');
        $this->como($yo)->get(route('notificaciones.index', ['estado' => 'raro']))
            ->assertSessionHasErrors('estado');
    }

    // --- Abrir, marcar y resumen -------------------------------------------------------------------------------------------------

    public function test_opening_marks_as_read_and_redirects_to_the_internal_url(): void
    {
        $yo = $this->usuario('Edificio');
        $id = $this->avisar($yo, ruta: '/horarios/solicitudes');

        $this->como($yo)->get(route('notificaciones.open', $id))->assertRedirect('/horarios/solicitudes');

        $this->assertNotNull($yo->notifications()->findOrFail($id)->read_at);
    }

    public function test_opening_someone_elses_notification_is_404_and_does_not_mark_it(): void
    {
        $yo = $this->usuario('Edificio');
        $otro = $this->usuario('Edificio');
        $id = $this->avisar($otro);

        $this->como($yo)->get(route('notificaciones.open', $id))->assertNotFound();

        $this->assertNull($otro->notifications()->findOrFail($id)->read_at);
    }

    public function test_read_all_only_touches_own_notifications(): void
    {
        $yo = $this->usuario('Edificio');
        $otro = $this->usuario('Edificio');
        $this->avisar($yo);
        $this->avisar($yo);
        $this->avisar($otro);

        $this->como($yo)->post(route('notificaciones.read-all'))->assertRedirect();

        $this->assertSame(0, $yo->unreadNotifications()->count());
        $this->assertSame(1, $otro->unreadNotifications()->count());
    }

    public function test_summary_returns_the_envelope_with_unread_count(): void
    {
        $yo = $this->usuario('Edificio');
        $this->avisar($yo);
        $this->avisar($yo);

        $this->como($yo)->getJson(route('notificaciones.summary'))
            ->assertOk()
            ->assertExactJson(['success' => true, 'data' => ['unread' => 2], 'error' => null, 'meta' => null]);
    }

    public function test_external_or_malformed_urls_fall_back_to_the_inbox(): void
    {
        $centro = app(NotificationCenter::class);
        $bandeja = route('notificaciones.index', [], false);

        $this->assertSame('/horarios?x=1', $centro->targetUrl('/horarios?x=1'));
        foreach (['https://malo.test/', '//malo.test', '/\\malo.test', 'javascript:alert(1)', "/ok\r\nLocation: x", '', null] as $url) {
            $this->assertSame($bandeja, $centro->targetUrl($url), (string) $url);
        }
    }

    public function test_bell_is_rendered_in_the_layout(): void
    {
        $yo = $this->usuario('Jefe de Zona');
        $this->avisar($yo);

        $this->como($yo)->get(route('notificaciones.index'))
            ->assertSee('notificacionesContador', false)
            ->assertSee('resumen', false);
    }
}
