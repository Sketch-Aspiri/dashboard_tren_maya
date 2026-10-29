<?php

namespace Tests\Feature\Secciones;

use App\Models\Seccion;
use App\Models\SeccionEnlace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithSecciones;
use Tests\TestCase;

/**
 * Pestaña Enlaces de una sección: direcciones web con título y nota.
 */
class EnlacesSeccionTest extends TestCase
{
    use InteractsWithSecciones, RefreshDatabase;

    private function enlaceEn(Seccion $seccion, string $titulo = 'Portal', string $url = 'https://example.com/a'): SeccionEnlace
    {
        $enlace = new SeccionEnlace(['titulo' => $titulo, 'url' => $url]);
        $enlace->forceFill(['seccion_id' => $seccion->id])->save();

        return $enlace;
    }

    public function test_admin_creates_a_link_and_it_is_logged(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();

        $this->post(route('secciones.enlaces.store', $seccion), [
            'titulo' => 'Tablero de vías', 'url' => 'https://example.com/vias', 'nota' => 'Se actualiza a diario',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('seccion_enlaces', ['seccion_id' => $seccion->id, 'titulo' => 'Tablero de vías', 'url' => 'https://example.com/vias']);
        $this->assertDatabaseHas('activity_log', ['subject_type' => SeccionEnlace::class, 'event' => 'created']);
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string}>
     */
    public static function datosInvalidos(): array
    {
        return [
            'sin título' => [['titulo' => '', 'url' => 'https://example.com'], 'titulo'],
            'sin url' => [['titulo' => 'x', 'url' => ''], 'url'],
            'javascript' => [['titulo' => 'x', 'url' => 'javascript:alert(1)'], 'url'],
            'ftp' => [['titulo' => 'x', 'url' => 'ftp://example.com/a'], 'url'],
            'texto' => [['titulo' => 'x', 'url' => 'no es url'], 'url'],
            'nota larga' => [['titulo' => 'x', 'url' => 'https://example.com', 'nota' => str_repeat('a', 501)], 'nota'],
        ];
    }

    /**
     * @param  array<string, string>  $datos
     */
    #[DataProvider('datosInvalidos')]
    public function test_rejects_invalid_links(array $datos, string $campo): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();

        $this->post(route('secciones.enlaces.store', $seccion), $datos)->assertSessionHasErrors($campo);

        $this->assertDatabaseCount('seccion_enlaces', 0);
    }

    public function test_admin_updates_and_deletes_a_link(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $enlace = $this->enlaceEn($seccion);

        $this->put(route('secciones.enlaces.update', [$seccion, $enlace]), ['titulo' => 'Nuevo', 'url' => 'https://example.com/b'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('seccion_enlaces', ['id' => $enlace->id, 'titulo' => 'Nuevo', 'url' => 'https://example.com/b']);

        $this->delete(route('secciones.enlaces.destroy', [$seccion, $enlace]))->assertRedirect();
        $this->assertSoftDeleted('seccion_enlaces', ['id' => $enlace->id]);
        $this->assertDatabaseHas('activity_log', ['subject_type' => SeccionEnlace::class, 'event' => 'deleted']);
    }

    public function test_link_of_another_section_cannot_be_touched(): void
    {
        $this->comoAdministrador();
        $propia = Seccion::factory()->create();
        $ajena = $this->enlaceEn(Seccion::factory()->create());

        $this->delete(route('secciones.enlaces.destroy', [$propia, $ajena]))->assertNotFound();
        $this->assertNotSoftDeleted('seccion_enlaces', ['id' => $ajena->id]);
    }

    public function test_user_without_role_cannot_manage_links(): void
    {
        $this->actingAsTwoFactorVerified($this->usuarioConRol(null));
        $seccion = Seccion::factory()->create();
        $enlace = $this->enlaceEn($seccion);

        $this->post(route('secciones.enlaces.store', $seccion), ['titulo' => 'x', 'url' => 'https://example.com'])->assertForbidden();
        $this->put(route('secciones.enlaces.update', [$seccion, $enlace]), ['titulo' => 'x', 'url' => 'https://example.com'])->assertForbidden();
        $this->delete(route('secciones.enlaces.destroy', [$seccion, $enlace]))->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $seccion = Seccion::factory()->create();

        $this->post(route('secciones.enlaces.store', $seccion), ['titulo' => 'x', 'url' => 'https://example.com'])->assertRedirect(route('login'));
    }

    public function test_links_tab_lists_links_safely(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $this->enlaceEn($seccion, '<b>Portal</b>', 'https://example.com/a?x=1&y=2');

        $this->get(route('secciones.show', [$seccion, 'tab' => 'enlaces']))
            ->assertOk()
            ->assertSee('https://example.com/a?x=1&amp;y=2', false)
            ->assertSee('rel="noopener noreferrer"', false)
            ->assertDontSee('<b>Portal</b>', false);
    }

    public function test_links_tab_is_available_in_documents_only_sections(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create(['solo_documentos' => true]);
        $this->enlaceEn($seccion, 'Visible');

        $this->get(route('secciones.show', $seccion))->assertOk()->assertSee('Enlaces (1)');
    }
}
