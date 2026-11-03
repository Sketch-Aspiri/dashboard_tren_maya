<?php

namespace Tests\Feature\Secciones;

use App\Enums\TipoColumna;
use App\Models\Estacion;
use App\Models\Seccion;
use App\Models\SeccionArchivo;
use App\Models\SeccionFila;
use App\Models\SeccionImportacion;
use App\Models\SeccionTabla;
use App\Models\User;
use App\Services\Secciones\ConsultaSeccionService;
use App\Services\Secciones\FilaService;
use App\Services\Secciones\ImportacionService;
use App\Support\ResolutorEstacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithSecciones;
use Tests\TestCase;

/**
 * Etiquetado de filas y documentos con su estación (Fase 2): las filas
 * existentes quedan sin etiqueta; la zona etiqueta en bloque por columna o a
 * mano; una cuenta Estación siempre etiqueta con la suya.
 */
class EtiquetadoEstacionTest extends TestCase
{
    use InteractsWithSecciones, RefreshDatabase;

    /** @var array<string, Estacion> */
    private array $estaciones = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Bacalar', 'Chetumal', 'Tulum', 'Tulum Aeropuerto'] as $orden => $nombre) {
            $this->estaciones[$nombre] = Estacion::factory()->create(['nombre' => $nombre, 'is_operativa' => true, 'orden' => $orden + 1]);
        }
    }

    /**
     * @return array{0: Seccion, 1: SeccionTabla}
     */
    private function seccionConTablaDeEstaciones(): array
    {
        $seccion = Seccion::factory()->create();
        $tabla = $this->tablaConColumnas($seccion, 'Personal', ['Nombre' => TipoColumna::Texto, 'Estación' => TipoColumna::Texto]);

        return [$seccion, $tabla];
    }

    private function usuarioEstacion(string $nombre): User
    {
        $usuario = $this->usuarioConRol('Estación');
        $usuario->forceFill(['estacion_id' => $this->estaciones[$nombre]->id])->save();

        return $usuario;
    }

    public function test_new_rows_start_without_station(): void
    {
        [, $tabla] = $this->seccionConTablaDeEstaciones();

        $fila = $this->filaEnTabla($tabla, ['nombre' => 'Ana', 'estacion' => 'Bacalar']);

        $this->assertNull($fila->fresh()->estacion_id);
    }

    public function test_resolver_matches_exact_names_only_after_normalizing(): void
    {
        $resolutor = new ResolutorEstacion;

        $this->assertSame($this->estaciones['Chetumal']->id, $resolutor->resolver('  CHETUMAL '));
        $this->assertSame($this->estaciones['Chetumal']->id, $resolutor->resolver('Estación Chetumal'));
        $this->assertSame($this->estaciones['Bacalar']->id, $resolutor->resolver('Bacalar (Q. Roo)'));
        $this->assertSame($this->estaciones['Tulum']->id, $resolutor->resolver('Tulum'));
        $this->assertSame($this->estaciones['Tulum Aeropuerto']->id, $resolutor->resolver('Tulum-Aeropuerto'));
        $this->assertNull($resolutor->resolver('Tulum Centro'));
        $this->assertNull($resolutor->resolver(''));
        $this->assertNull($resolutor->resolver(null));
    }

    public function test_preview_lists_matches_and_unmatched_values(): void
    {
        [$seccion, $tabla] = $this->seccionConTablaDeEstaciones();
        $this->filaEnTabla($tabla, ['nombre' => 'Ana', 'estacion' => 'bacalar']);
        $this->filaEnTabla($tabla, ['nombre' => 'Beto', 'estacion' => 'Tulum']);
        $this->filaEnTabla($tabla, ['nombre' => 'Carla', 'estacion' => 'Marte']);
        $this->comoAdministrador();

        $respuesta = $this->postJson(route('secciones.tablas.estaciones.previsualizar', [$seccion, $tabla]), ['columna' => 'estacion'])
            ->assertOk()
            ->json('data');

        $this->assertSame(['Bacalar' => 1, 'Tulum' => 1], $respuesta['coincidencias']);
        $this->assertSame(['Marte' => 1], $respuesta['sin_coincidencia']);
        $this->assertSame(3, $respuesta['total']);
        $this->assertSame(0, SeccionFila::query()->whereNotNull('estacion_id')->count(), 'previsualizar no escribe');
    }

    public function test_apply_tags_only_untagged_rows_by_default_and_logs_one_summary_event(): void
    {
        [$seccion, $tabla] = $this->seccionConTablaDeEstaciones();
        $ana = $this->filaEnTabla($tabla, ['nombre' => 'Ana', 'estacion' => 'Bacalar']);
        $beto = $this->filaEnTabla($tabla, ['nombre' => 'Beto', 'estacion' => 'Chetumal']);
        $marte = $this->filaEnTabla($tabla, ['nombre' => 'Carla', 'estacion' => 'Marte']);
        $beto->forceFill(['estacion_id' => $this->estaciones['Tulum']->id])->save();
        $this->comoAdministrador();
        Activity::query()->delete();

        $this->post(route('secciones.tablas.estaciones.aplicar', [$seccion, $tabla]), ['columna' => 'estacion'])
            ->assertRedirect();

        $this->assertSame($this->estaciones['Bacalar']->id, $ana->fresh()->estacion_id);
        $this->assertSame($this->estaciones['Tulum']->id, $beto->fresh()->estacion_id, 'una etiqueta puesta a mano no se pisa');
        $this->assertNull($marte->fresh()->estacion_id);
        $this->assertSame('estacion', $tabla->fresh()->columna_estacion_clave);

        $eventos = Activity::query()->where('event', 'filas_etiquetadas_estacion')->get();
        $this->assertCount(1, $eventos);
        $this->assertSame(['Bacalar' => 1], $eventos->first()->properties['por_estacion']);
    }

    public function test_apply_with_overwrite_replaces_existing_tags_and_attachments_follow(): void
    {
        [$seccion, $tabla] = $this->seccionConTablaDeEstaciones();
        $ana = $this->filaEnTabla($tabla, ['nombre' => 'Ana', 'estacion' => 'Bacalar']);
        $ana->forceFill(['estacion_id' => $this->estaciones['Tulum']->id])->save();
        $adjunto = $this->adjuntoDe($seccion, $ana);
        $this->comoAdministrador();

        $this->post(route('secciones.tablas.estaciones.aplicar', [$seccion, $tabla]), ['columna' => 'estacion', 'sobrescribir' => '1'])
            ->assertRedirect();

        $this->assertSame($this->estaciones['Bacalar']->id, $ana->fresh()->estacion_id);
        $this->assertSame($this->estaciones['Bacalar']->id, $adjunto->fresh()->estacion_id);
    }

    public function test_bulk_tagging_rejects_columns_of_other_tables_and_non_text_columns(): void
    {
        [$seccion, $tabla] = $this->seccionConTablaDeEstaciones();
        $otra = $this->tablaConColumnas($seccion, 'Otra', ['Cantidad' => TipoColumna::Numero, 'Solo aqui' => TipoColumna::Texto]);
        $this->comoAdministrador();

        $this->post(route('secciones.tablas.estaciones.aplicar', [$seccion, $tabla]), ['columna' => 'solo_aqui'])->assertSessionHasErrors('columna');
        $this->post(route('secciones.tablas.estaciones.aplicar', [$seccion, $otra]), ['columna' => 'cantidad'])->assertSessionHasErrors('columna');
    }

    public function test_station_accounts_cannot_tag(): void
    {
        [$seccion, $tabla] = $this->seccionConTablaDeEstaciones();
        $this->actingAsTwoFactorVerified($this->usuarioEstacion('Bacalar'));

        $this->post(route('secciones.tablas.estaciones.aplicar', [$seccion, $tabla]), ['columna' => 'estacion'])->assertForbidden();
        $this->postJson(route('secciones.tablas.estaciones.previsualizar', [$seccion, $tabla]), ['columna' => 'estacion'])->assertForbidden();
    }

    public function test_zone_user_sets_and_changes_the_station_of_a_row_and_its_attachments_follow(): void
    {
        [$seccion, $tabla] = $this->seccionConTablaDeEstaciones();
        $this->comoAdministrador();

        $this->post(route('secciones.filas.store', $seccion), [
            'tabla' => $tabla->id,
            'datos' => ['nombre' => 'Ana'],
            'estacion_id' => $this->estaciones['Chetumal']->id,
        ])->assertRedirect();

        $fila = SeccionFila::query()->where('seccion_tabla_id', $tabla->id)->sole();
        $this->assertSame($this->estaciones['Chetumal']->id, $fila->estacion_id);

        $adjunto = $this->adjuntoDe($seccion, $fila);

        $this->put(route('secciones.filas.update', [$seccion, $fila]), [
            'datos' => ['nombre' => 'Ana'],
            'estacion_id' => $this->estaciones['Bacalar']->id,
        ])->assertRedirect();

        $this->assertSame($this->estaciones['Bacalar']->id, $fila->fresh()->estacion_id);
        $this->assertSame($this->estaciones['Bacalar']->id, $adjunto->fresh()->estacion_id);
    }

    public function test_updating_a_row_without_the_station_field_keeps_its_station(): void
    {
        [$seccion, $tabla] = $this->seccionConTablaDeEstaciones();
        $fila = $this->filaEnTabla($tabla, ['nombre' => 'Ana']);
        $fila->forceFill(['estacion_id' => $this->estaciones['Bacalar']->id])->save();
        $this->comoAdministrador();

        $this->put(route('secciones.filas.update', [$seccion, $fila]), ['datos' => ['nombre' => 'Ana Maria']])->assertRedirect();

        $this->assertSame($this->estaciones['Bacalar']->id, $fila->fresh()->estacion_id);
    }

    public function test_station_account_always_tags_rows_with_its_own_station(): void
    {
        [, $tabla] = $this->seccionConTablaDeEstaciones();
        $usuario = $this->usuarioEstacion('Bacalar');

        $fila = app(FilaService::class)->crear($tabla, ['nombre' => 'Ana'], $usuario, [], $this->estaciones['Chetumal']->id);

        $this->assertSame($this->estaciones['Bacalar']->id, $fila->estacion_id);
    }

    public function test_import_into_a_table_with_a_remembered_column_tags_new_rows_and_keeps_manual_tags(): void
    {
        [, $tabla] = $this->seccionConTablaDeEstaciones();
        $tabla->forceFill(['columna_estacion_clave' => 'estacion'])->save();
        $existente = $this->filaEnTabla($tabla, ['nombre' => 'Vieja', 'estacion' => 'Bacalar']);
        $existente->forceFill(['estacion_id' => $this->estaciones['Tulum']->id])->save();
        $servicio = app(ImportacionService::class);
        $ahora = now();

        $servicio->insertarFilas($tabla, new SeccionImportacion, [
            ['nombre' => 'Nueva', 'estacion' => 'Estación Chetumal'],
            ['nombre' => 'Rara', 'estacion' => 'Marte'],
        ], null, $ahora);
        $servicio->actualizarFilas([$existente->id => ['antes' => [], 'despues' => ['nombre' => 'Vieja', 'estacion' => 'Bacalar']]], null, $ahora, $tabla);

        $this->assertSame($this->estaciones['Chetumal']->id, SeccionFila::query()->where('texto_busqueda', 'like', '%nueva%')->value('estacion_id'));
        $this->assertNull(SeccionFila::query()->where('texto_busqueda', 'like', '%rara%')->value('estacion_id'));
        $this->assertSame($this->estaciones['Tulum']->id, $existente->fresh()->estacion_id, 'no se pisa una etiqueta manual');
    }

    public function test_station_filter_lists_only_untagged_or_the_chosen_station_for_zone_users(): void
    {
        [, $tabla] = $this->seccionConTablaDeEstaciones();
        $this->filaEnTabla($tabla, ['nombre' => 'Sin etiqueta']);
        $this->filaEnTabla($tabla, ['nombre' => 'De Bacalar'])->forceFill(['estacion_id' => $this->estaciones['Bacalar']->id])->save();
        $consulta = app(ConsultaSeccionService::class);
        $base = ['q' => null, 'sort' => null, 'direction' => 'asc'];

        $sin = $consulta->consultaFilas($tabla->fresh(), $base + ['estacion' => 'sin'])->get();
        $bacalar = $consulta->consultaFilas($tabla->fresh(), $base + ['estacion' => (string) $this->estaciones['Bacalar']->id])->get();

        $this->assertSame(['Sin etiqueta'], $sin->pluck('datos.nombre')->all());
        $this->assertSame(['De Bacalar'], $bacalar->pluck('datos.nombre')->all());
    }

    public function test_document_station_can_be_changed_by_zone_users_only(): void
    {
        [$seccion, $tabla] = $this->seccionConTablaDeEstaciones();
        $archivo = $this->adjuntoDe($seccion, $this->filaEnTabla($tabla, ['nombre' => 'Ana']));
        $this->actingAsTwoFactorVerified($this->usuarioEstacion('Bacalar'));

        $this->put(route('secciones.archivos.estacion', [$seccion, $archivo]), ['estacion_id' => $this->estaciones['Bacalar']->id])->assertForbidden();

        $this->comoAdministrador();
        $this->put(route('secciones.archivos.estacion', [$seccion, $archivo]), ['estacion_id' => $this->estaciones['Chetumal']->id])->assertRedirect();

        $this->assertSame($this->estaciones['Chetumal']->id, $archivo->fresh()->estacion_id);
    }

    private function adjuntoDe(Seccion $seccion, SeccionFila $fila): SeccionArchivo
    {
        $archivo = new SeccionArchivo([
            'seccion_fila_id' => $fila->id,
            'nombre_original' => 'oficio.pdf',
            'ruta' => 'secciones/'.$seccion->id.'/x.pdf',
            'mime' => 'application/pdf',
            'extension' => 'pdf',
            'tamano' => 10,
        ]);
        $archivo->forceFill(['seccion_id' => $seccion->id, 'estacion_id' => $fila->estacion_id])->save();

        return $archivo;
    }
}
