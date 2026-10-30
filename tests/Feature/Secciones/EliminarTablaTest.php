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
}
