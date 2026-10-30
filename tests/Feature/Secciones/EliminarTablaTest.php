<?php

namespace Tests\Feature\Secciones;

use App\Enums\TipoColumna;
use App\Models\Seccion;
use App\Models\SeccionFila;
use App\Models\SeccionTabla;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithSecciones;
use Tests\TestCase;

/**
 * Borrar hojas (tablas) de una sección desde la vista show.
 */
class EliminarTablaTest extends TestCase
{
    use InteractsWithSecciones, RefreshDatabase;

    /**
     * @return array{0: Seccion, 1: SeccionTabla, 2: SeccionTabla}
     */
    private function seccionConDosTablas(): array
    {
        $seccion = Seccion::factory()->create();
        $playa = $this->tablaConColumnas($seccion, '24_Playa', ['Empleado' => TipoColumna::Texto]);
        $tulum = $this->tablaConColumnas($seccion, '25_Tulum', ['Turno' => TipoColumna::Texto]);
        $this->filaEnTabla($playa, ['empleado' => 'Ana']);
        $this->filaEnTabla($tulum, ['turno' => 'Matutino']);

        return [$seccion, $playa, $tulum];
    }

    public function test_admin_can_delete_a_sheet_and_it_is_logged(): void
    {
        $usuario = $this->comoAdministrador();
        [$seccion, $playa, $tulum] = $this->seccionConDosTablas();
        $filaPlaya = $playa->filas()->first();
        $columnaPlaya = $playa->columnas()->first();

        $this->delete(route('secciones.tablas.destroy', [$seccion, $playa]))
            ->assertRedirect(route('secciones.show', $seccion))
            ->assertSessionHas('status');

        $this->assertSoftDeleted($playa);
        $this->assertSoftDeleted($filaPlaya);
        $this->assertDatabaseMissing('seccion_columnas', ['id' => $columnaPlaya->id]);
        $this->assertNotSoftDeleted($tulum);
        $this->assertSame(1, $tulum->filas()->count());
        $this->assertSame(1, $tulum->columnas()->count());
        $this->assertTrue(Activity::query()
            ->where('subject_type', SeccionTabla::class)
            ->where('subject_id', $playa->id)
            ->where('event', 'deleted')
            ->where('causer_id', $usuario->id)
            ->exists());
        $this->assertFalse(
            Activity::query()
                ->where('subject_type', SeccionFila::class)
                ->where('subject_id', $filaPlaya->id)
                ->where('event', 'deleted')
                ->exists(),
            'no se registra activitylog por cada fila al borrar la hoja',
        );
    }

    public function test_guest_cannot_delete_a_sheet(): void
    {
        [$seccion, $playa] = $this->seccionConDosTablas();

        $this->delete(route('secciones.tablas.destroy', [$seccion, $playa]))
            ->assertRedirect(route('login'));
        $this->assertNotSoftDeleted($playa);
    }

    public function test_estacion_role_cannot_delete_a_sheet(): void
    {
        [$seccion, $playa] = $this->seccionConDosTablas();
        $this->actingAsTwoFactorVerified($this->usuarioConRol('Estación'));

        $this->delete(route('secciones.tablas.destroy', [$seccion, $playa]))->assertForbidden();
        $this->assertNotSoftDeleted($playa);
    }

    public function test_cannot_delete_tabla_of_another_seccion(): void
    {
        $this->comoAdministrador();
        [, $playa] = $this->seccionConDosTablas();
        $otra = Seccion::factory()->create();

        $this->delete(route('secciones.tablas.destroy', [$otra, $playa]))->assertNotFound();
        $this->assertNotSoftDeleted($playa);
    }

    public function test_deleting_last_sheet_is_allowed_and_default_reappears_on_show(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        // La factory ya crea "Principal" vía tablaActiva / observer; usamos esa única hoja.
        $unica = $seccion->tablaActiva();
        $unica->columnas()->create([
            'seccion_id' => $seccion->id,
            'nombre' => 'Nombre',
            'clave' => 'nombre',
            'tipo' => TipoColumna::Texto,
            'orden' => 1,
        ]);
        $this->filaEnTabla($unica, ['nombre' => 'X']);

        $this->delete(route('secciones.tablas.destroy', [$seccion, $unica]))
            ->assertRedirect(route('secciones.show', $seccion));

        $this->assertSoftDeleted($unica);
        $this->assertSame(0, $seccion->tablas()->count());

        // Al visitar show, tablaActiva() recrea "Principal".
        $this->get(route('secciones.show', $seccion))->assertOk();
        $this->assertSame(1, $seccion->fresh()->tablas()->count());
        $this->assertSame(SeccionTabla::NOMBRE_POR_DEFECTO, $seccion->fresh()->tablas()->value('nombre'));
    }

    public function test_admin_can_delete_column_from_table_view_and_returns_to_show(): void
    {
        $this->comoAdministrador();
        [$seccion, $playa, $tulum] = $this->seccionConDosTablas();
        $columna = $playa->columnas()->where('clave', 'empleado')->sole();

        $this->delete(route('secciones.columnas.destroy', [$seccion, $columna]), ['volver' => 'tabla'])
            ->assertRedirect(route('secciones.show', [$seccion, 'tabla' => $playa->id]));

        $this->assertDatabaseMissing('seccion_columnas', ['id' => $columna->id]);
        $this->assertArrayNotHasKey('empleado', $playa->filas()->first()->datos);
        $this->assertSame('Matutino', $tulum->filas()->first()->datos['turno']);
    }

    public function test_admin_sees_eliminar_hoja_control_when_section_has_several_tables(): void
    {
        $this->comoAdministrador();
        [$seccion, $playa, $tulum] = $this->seccionConDosTablas();

        $this->get(route('secciones.show', $seccion))
            ->assertOk()
            ->assertSee('Eliminar hoja', false)
            ->assertSee(__('Hoja actual: :nombre', ['nombre' => $playa->nombre]), false)
            ->assertSee(
                __('¿Eliminar la hoja «:nombre» y todos sus registros?', ['nombre' => $playa->nombre]),
                false,
            )
            ->assertSee(route('secciones.tablas.destroy', [$seccion, $playa]), false)
            ->assertDontSee(route('secciones.tablas.destroy', [$seccion, $tulum]), false);

        $this->get(route('secciones.show', [$seccion, 'tabla' => $tulum->id]))
            ->assertOk()
            ->assertSee('Eliminar hoja', false)
            ->assertSee(__('Hoja actual: :nombre', ['nombre' => $tulum->nombre]), false)
            ->assertSee(route('secciones.tablas.destroy', [$seccion, $tulum]), false)
            ->assertDontSee(route('secciones.tablas.destroy', [$seccion, $playa]), false);
    }

    public function test_sheet_pills_show_the_full_table_name_not_a_truncated_label(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $nombreLargo = 'EDIFICIO ZONAL ESTE - PERSONAL OPERATIVO';
        $edificio = $this->tablaConColumnas($seccion, $nombreLargo, ['Empleado' => TipoColumna::Texto]);
        $this->tablaConColumnas($seccion, 'PUERTO MORELOS', ['Empleado' => TipoColumna::Texto]);
        $this->filaEnTabla($edificio, ['empleado' => 'Ana']);

        $html = $this->get(route('secciones.show', [$seccion, 'tabla' => $edificio->id]))
            ->assertOk()
            ->assertSee($nombreLargo.' (1)', false)
            ->assertSee('PUERTO MORELOS (0)', false)
            ->assertSee(__('Hoja actual: :nombre', ['nombre' => $nombreLargo]), false)
            ->assertSee('title="'.$nombreLargo.'"', false)
            ->getContent();

        $this->assertSame(1, preg_match(
            '/(<nav\b[^>]*aria-label="'.preg_quote(__('Tablas de la sección'), '/').'"[^>]*>.*?<\/nav>)/s',
            $html,
            $nav,
        ));
        $pildoras = $nav[1];
        $this->assertStringContainsString('flex-wrap', $pildoras);
        $this->assertStringContainsString('shrink-0', $pildoras);
        $this->assertStringContainsString('max-w-full', $pildoras);
        $this->assertStringNotContainsString('overflow-x-auto', $pildoras);
        $this->assertStringNotContainsString('whitespace-nowrap', $pildoras);
        $this->assertStringNotContainsString('truncate', $pildoras);
    }

    public function test_admin_does_not_see_eliminar_hoja_with_a_single_table(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $seccion->tablaActiva();
        $this->assertSame(1, $seccion->tablas()->count());

        $this->get(route('secciones.show', $seccion))
            ->assertOk()
            ->assertDontSee('Eliminar hoja', false)
            ->assertDontSee(__('Tablas de la sección'), false);
    }

    public function test_estacion_role_cannot_view_section_show_so_no_eliminar_hoja(): void
    {
        [$seccion] = $this->seccionConDosTablas();
        $this->actingAsTwoFactorVerified($this->usuarioConRol('Estación'));

        // Estación no tiene view en SeccionPolicy; el control nunca se renderiza.
        $this->get(route('secciones.show', $seccion))->assertForbidden();
    }

    public function test_guest_is_redirected_from_section_show(): void
    {
        [$seccion] = $this->seccionConDosTablas();

        $this->get(route('secciones.show', $seccion))
            ->assertRedirect(route('login'));
    }
}
