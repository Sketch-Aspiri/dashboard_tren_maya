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
use App\Services\Secciones\ExportacionService;
use App\Services\Secciones\FotoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithSecciones;
use Tests\TestCase;

/**
 * Permisos de una cuenta Estación por sección completa o por hoja (Ver /
 * Editar), nunca por fila: con permiso sobre una hoja ve y edita TODAS sus
 * filas; sin permiso, la hoja/sección/documento no existe para ella (404).
 */
class EstacionFugaDatosTest extends TestCase
{
    use InteractsWithSecciones, RefreshDatabase;

    private const SIN_FILTROS = ['q' => null, 'sort' => null, 'direction' => 'asc'];

    private Estacion $bacalar;

    private Estacion $chetumal;

    private Seccion $seccion;

    private SeccionTabla $personal;

    private SeccionTabla $mantenimiento;

    private SeccionFila $filaBacalar;

    private SeccionFila $filaChetumal;

    private SeccionFila $filaSinEtiqueta;

    private SeccionFila $filaMantenimiento;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->bacalar = Estacion::factory()->create(['nombre' => 'Bacalar', 'is_operativa' => true, 'orden' => 1]);
        $this->chetumal = Estacion::factory()->create(['nombre' => 'Chetumal', 'is_operativa' => true, 'orden' => 2]);

        $this->seccion = Seccion::factory()->create();
        $this->personal = $this->tablaConColumnas($this->seccion, 'Personal', ['Nombre' => TipoColumna::Texto, 'Foto' => TipoColumna::Foto]);
        $this->mantenimiento = $this->tablaConColumnas($this->seccion, 'Mantenimiento', ['Nombre' => TipoColumna::Texto]);
        $this->filaBacalar = $this->fila($this->personal, 'MarcadorBacalar', $this->bacalar);
        $this->filaChetumal = $this->fila($this->personal, 'MarcadorChetumal', $this->chetumal);
        $this->filaSinEtiqueta = $this->fila($this->personal, 'MarcadorHuerfana', null);
        $this->filaMantenimiento = $this->fila($this->mantenimiento, 'MarcadorMantenimiento', null);
    }

    private function fila(SeccionTabla $tabla, string $nombre, ?Estacion $estacion): SeccionFila
    {
        $fila = $this->filaEnTabla($tabla, ['nombre' => $nombre]);
        $fila->forceFill(['estacion_id' => $estacion?->id])->save();

        return $fila;
    }

    private function cuentaDe(Estacion $estacion): User
    {
        $usuario = $this->usuarioConRol('Estación');
        $usuario->forceFill(['estacion_id' => $estacion->id])->save();

        return $usuario;
    }

    private function permisoSeccion(Estacion $estacion, string $nivel, ?Seccion $seccion = null): void
    {
        ($seccion ?? $this->seccion)->estacionesAprobadas()->syncWithoutDetaching([$estacion->id => ['nivel' => $nivel]]);
    }

    private function permisoHoja(Estacion $estacion, string $nivel, SeccionTabla $tabla): void
    {
        $tabla->estacionesAprobadas()->syncWithoutDetaching([$estacion->id => ['nivel' => $nivel]]);
    }

    private function documento(?Estacion $estacion, string $nombre, ?SeccionFila $fila = null): SeccionArchivo
    {
        $archivo = new SeccionArchivo([
            'seccion_fila_id' => $fila?->id,
            'nombre_original' => $nombre,
            'ruta' => 'secciones/'.$this->seccion->id.'/'.$nombre,
            'mime' => 'application/pdf',
            'extension' => 'pdf',
            'tamano' => 4,
        ]);
        $archivo->forceFill(['seccion_id' => $this->seccion->id, 'estacion_id' => $estacion?->id])->save();
        Storage::disk('local')->put($archivo->ruta, '%PDF');

        return $archivo;
    }

    public function test_without_permission_the_section_rows_and_documents_do_not_exist_for_the_station(): void
    {
        $documento = $this->documento($this->bacalar, 'propio.pdf');
        $this->actingAsTwoFactorVerified($this->cuentaDe($this->bacalar));

        $this->get(route('secciones.show', $this->seccion))->assertNotFound();
        $this->put(route('secciones.filas.update', [$this->seccion, $this->filaBacalar]), ['datos' => ['nombre' => 'X']])->assertNotFound();
        $this->get(route('secciones.archivos.show', [$this->seccion, $documento]))->assertNotFound();
        $this->assertSame('MarcadorBacalar', $this->filaBacalar->fresh()->datos['nombre']);
    }

    public function test_view_permission_shows_every_row_of_every_sheet_regardless_of_the_row_station(): void
    {
        $this->permisoSeccion($this->bacalar, 'ver');
        $this->actingAsTwoFactorVerified($this->cuentaDe($this->bacalar));

        $this->get(route('secciones.show', $this->seccion))
            ->assertOk()
            ->assertSee('MarcadorBacalar')
            ->assertSee('MarcadorChetumal')
            ->assertSee('MarcadorHuerfana')
            ->assertSee('Tabla (3)', false)
            ->assertSee('Solo lectura')
            ->assertDontSee('Agregar registro')
            ->assertDontSee('Arrastra aquí');

        $this->get(route('secciones.show', [$this->seccion, 'tabla' => $this->mantenimiento->id]))
            ->assertOk()
            ->assertSee('MarcadorMantenimiento');
    }

    public function test_view_permission_cannot_create_edit_delete_or_upload(): void
    {
        $this->permisoSeccion($this->bacalar, 'ver');
        $documento = $this->documento($this->bacalar, 'propio.pdf');
        $this->actingAsTwoFactorVerified($this->cuentaDe($this->bacalar));

        $this->post(route('secciones.filas.store', $this->seccion), ['tabla' => $this->personal->id, 'datos' => ['nombre' => 'Nueva']])->assertForbidden();
        $this->put(route('secciones.filas.update', [$this->seccion, $this->filaBacalar]), ['datos' => ['nombre' => 'Hack']])->assertForbidden();
        $this->delete(route('secciones.filas.destroy', [$this->seccion, $this->filaBacalar]))->assertForbidden();
        $this->postJson(route('secciones.archivos.store', $this->seccion), [
            'archivos' => [UploadedFile::fake()->createWithContent('datos.csv', "a\n1\n")],
        ])->assertForbidden();
        $this->delete(route('secciones.archivos.destroy', [$this->seccion, $documento]))->assertForbidden();

        $this->assertSame(4, SeccionFila::query()->count());
        $this->assertSame('MarcadorBacalar', $this->filaBacalar->fresh()->datos['nombre']);
        $this->assertNull($documento->fresh()->deleted_at);
    }

    public function test_edit_permission_edits_and_deletes_any_row_of_the_section(): void
    {
        $this->permisoSeccion($this->bacalar, 'editar');
        $this->actingAsTwoFactorVerified($this->cuentaDe($this->bacalar));

        $this->put(route('secciones.filas.update', [$this->seccion, $this->filaChetumal]), ['datos' => ['nombre' => 'EditadaPorBacalar']])->assertRedirect();
        $this->put(route('secciones.filas.update', [$this->seccion, $this->filaSinEtiqueta]), ['datos' => ['nombre' => 'TambienEditada']])->assertRedirect();
        $this->delete(route('secciones.filas.destroy', [$this->seccion, $this->filaMantenimiento]))->assertRedirect();

        $this->assertSame('EditadaPorBacalar', $this->filaChetumal->fresh()->datos['nombre']);
        $this->assertSame('TambienEditada', $this->filaSinEtiqueta->fresh()->datos['nombre']);
        $this->assertSoftDeleted($this->filaMantenimiento);
        $this->assertSame(1, Activity::query()->where('subject_type', SeccionFila::class)->where('event', 'deleted')->count());
    }

    public function test_rows_created_by_a_station_are_tagged_with_its_own_station_and_it_cannot_retag_rows(): void
    {
        $this->permisoSeccion($this->bacalar, 'editar');
        $this->actingAsTwoFactorVerified($this->cuentaDe($this->bacalar));

        $this->post(route('secciones.filas.store', $this->seccion), [
            'tabla' => $this->personal->id,
            'datos' => ['nombre' => 'NuevaDeBacalar'],
            'estacion_id' => $this->chetumal->id,
        ])->assertRedirect();
        $this->put(route('secciones.filas.update', [$this->seccion, $this->filaChetumal]), [
            'datos' => ['nombre' => 'Sigue'],
            'estacion_id' => $this->bacalar->id,
        ])->assertRedirect();

        $this->assertSame($this->bacalar->id, SeccionFila::query()->where('texto_busqueda', 'like', '%nuevadebacalar%')->value('estacion_id'));
        $this->assertSame($this->chetumal->id, $this->filaChetumal->fresh()->estacion_id);
    }

    public function test_sheet_permission_opens_only_that_sheet(): void
    {
        $this->permisoHoja($this->bacalar, 'editar', $this->mantenimiento);
        $this->actingAsTwoFactorVerified($this->cuentaDe($this->bacalar));

        // Sin ?tabla= se abre la primera hoja permitida, nunca la primera de la sección.
        $this->get(route('secciones.show', $this->seccion))
            ->assertOk()
            ->assertSee('MarcadorMantenimiento')
            ->assertDontSee('MarcadorBacalar')
            ->assertDontSee('Personal (', false)
            ->assertSee('Agregar registro');

        $this->get(route('secciones.show', [$this->seccion, 'tabla' => $this->personal->id]))->assertSessionHasErrors('tabla');

        $this->put(route('secciones.filas.update', [$this->seccion, $this->filaMantenimiento]), ['datos' => ['nombre' => 'EditadaHoja']])->assertRedirect();
        $this->put(route('secciones.filas.update', [$this->seccion, $this->filaBacalar]), ['datos' => ['nombre' => 'Hack']])->assertNotFound();
        $this->post(route('secciones.filas.store', $this->seccion), ['tabla' => $this->personal->id, 'datos' => ['nombre' => 'Hack']])->assertNotFound();

        $this->assertSame('EditadaHoja', $this->filaMantenimiento->fresh()->datos['nombre']);
        $this->assertSame('MarcadorBacalar', $this->filaBacalar->fresh()->datos['nombre']);
    }

    public function test_sheet_permission_does_not_open_the_loose_documents_of_the_section(): void
    {
        $suelto = $this->documento(null, 'suelto.pdf');
        $adjunto = $this->documento(null, 'adjunto.pdf', $this->filaMantenimiento);
        $ajeno = $this->documento(null, 'ajeno.pdf', $this->filaBacalar);
        $this->permisoHoja($this->bacalar, 'editar', $this->mantenimiento);
        $this->actingAsTwoFactorVerified($this->cuentaDe($this->bacalar));

        $this->get(route('secciones.show', [$this->seccion, 'tab' => 'documentos']))
            ->assertOk()
            ->assertSee('adjunto.pdf')
            ->assertDontSee('suelto.pdf')
            ->assertDontSee('ajeno.pdf');

        $this->get(route('secciones.archivos.show', [$this->seccion, $adjunto]))->assertOk();
        $this->get(route('secciones.archivos.show', [$this->seccion, $suelto]))->assertNotFound();
        $this->get(route('secciones.archivos.show', [$this->seccion, $ajeno]))->assertNotFound();
        $this->postJson(route('secciones.archivos.store', $this->seccion), [
            'archivos' => [UploadedFile::fake()->createWithContent('datos.csv', "a\n1\n")],
        ])->assertNotFound();
    }

    public function test_attaching_documents_to_a_row_requires_edit_on_its_sheet(): void
    {
        $this->permisoSeccion($this->bacalar, 'ver');
        $this->permisoHoja($this->bacalar, 'editar', $this->mantenimiento);
        $this->actingAsTwoFactorVerified($this->cuentaDe($this->bacalar));

        $this->postJson(route('secciones.archivos.store', $this->seccion), [
            'archivos' => [UploadedFile::fake()->createWithContent('a.csv', "a\n1\n")],
            'seccion_fila_id' => $this->filaBacalar->id,
        ])->assertForbidden();
        $this->postJson(route('secciones.archivos.store', $this->seccion), [
            'archivos' => [UploadedFile::fake()->createWithContent('b.csv', "a\n1\n")],
            'seccion_fila_id' => $this->filaMantenimiento->id,
        ])->assertCreated();

        $this->assertSame(['b.csv'], SeccionArchivo::query()->pluck('nombre_original')->all());
    }

    public function test_section_view_plus_sheet_edit_only_edits_that_sheet(): void
    {
        $this->permisoSeccion($this->bacalar, 'ver');
        $this->permisoHoja($this->bacalar, 'editar', $this->mantenimiento);
        $this->actingAsTwoFactorVerified($this->cuentaDe($this->bacalar));

        $this->put(route('secciones.filas.update', [$this->seccion, $this->filaMantenimiento]), ['datos' => ['nombre' => 'Si']])->assertRedirect();
        $this->put(route('secciones.filas.update', [$this->seccion, $this->filaBacalar]), ['datos' => ['nombre' => 'No']])->assertForbidden();
    }

    public function test_sheet_permission_of_another_station_does_not_leak(): void
    {
        $this->permisoHoja($this->chetumal, 'editar', $this->mantenimiento);
        $this->actingAsTwoFactorVerified($this->cuentaDe($this->bacalar));

        $this->get(route('secciones.show', $this->seccion))->assertNotFound();
    }

    public function test_a_deleted_sheet_stops_granting_access(): void
    {
        $this->permisoHoja($this->bacalar, 'editar', $this->mantenimiento);
        $this->mantenimiento->delete();
        $this->actingAsTwoFactorVerified($this->cuentaDe($this->bacalar));

        $this->get(route('secciones.show', $this->seccion))->assertNotFound();

        // Restaurar la hoja no devuelve el permiso: se borró con la baja.
        $this->mantenimiento->restore();
        $this->assertSame(0, $this->mantenimiento->estacionesAprobadas()->count());
        $this->get(route('secciones.show', $this->seccion))->assertNotFound();
    }

    public function test_a_hidden_section_cuts_the_inherited_edit_permission(): void
    {
        $oculta = Seccion::factory()->create(['menu_grupo_id' => $this->seccion->menu_grupo_id, 'parent_id' => $this->seccion->id, 'is_visible' => false]);
        $nieta = Seccion::factory()->create(['menu_grupo_id' => $this->seccion->menu_grupo_id, 'parent_id' => $oculta->id]);
        $tablaNieta = $this->tablaConColumnas($nieta, 'Nieta', ['Nombre' => TipoColumna::Texto]);
        $filaNieta = $this->filaEnTabla($tablaNieta, ['nombre' => 'MarcadorNieta']);
        $this->permisoSeccion($this->bacalar, 'editar');
        $this->actingAsTwoFactorVerified($this->cuentaDe($this->bacalar));

        $this->get(route('secciones.show', $oculta))->assertNotFound();
        $this->put(route('secciones.filas.update', [$nieta, $filaNieta]), ['datos' => ['nombre' => 'Hack']])->assertNotFound();
        $this->assertSame('MarcadorNieta', $filaNieta->fresh()->datos['nombre']);
    }

    public function test_edit_for_all_stations_and_inheritance_to_subsections(): void
    {
        $hija = Seccion::factory()->create(['menu_grupo_id' => $this->seccion->menu_grupo_id, 'parent_id' => $this->seccion->id]);
        $tablaHija = $this->tablaConColumnas($hija, 'Hija', ['Nombre' => TipoColumna::Texto]);
        $filaHija = $this->filaEnTabla($tablaHija, ['nombre' => 'MarcadorHija']);
        $this->seccion->forceFill(['acceso_todas' => 'ver'])->save();
        $this->permisoSeccion($this->chetumal, 'editar');

        $this->actingAsTwoFactorVerified($this->cuentaDe($this->bacalar));
        $this->put(route('secciones.filas.update', [$hija, $filaHija]), ['datos' => ['nombre' => 'No']])->assertForbidden();

        $this->actingAsTwoFactorVerified($this->cuentaDe($this->chetumal));
        $this->put(route('secciones.filas.update', [$hija, $filaHija]), ['datos' => ['nombre' => 'HeredadoEditar']])->assertRedirect();
        $this->assertSame('HeredadoEditar', $filaHija->fresh()->datos['nombre']);
    }

    public function test_the_row_station_filter_still_works_for_zone_users_only(): void
    {
        $this->permisoSeccion($this->bacalar, 'ver');

        $this->actingAsTwoFactorVerified($this->cuentaDe($this->bacalar));
        $this->get(route('secciones.show', [$this->seccion, 'estacion' => $this->chetumal->id]))
            ->assertOk()
            ->assertSee('MarcadorBacalar');

        $this->actingAsTwoFactorVerified($this->usuarioConRol('Edificio'));
        $this->get(route('secciones.show', [$this->seccion, 'estacion' => $this->chetumal->id]))
            ->assertOk()
            ->assertSee('MarcadorChetumal')
            ->assertDontSee('MarcadorBacalar');
    }

    public function test_export_respects_the_sheet_permissions(): void
    {
        $this->permisoHoja($this->bacalar, 'ver', $this->mantenimiento);
        $usuario = $this->cuentaDe($this->bacalar);

        $libro = app(ExportacionService::class)->libro($this->mantenimiento->fresh(), self::SIN_FILTROS, $usuario);
        $celdas = collect($libro->getActiveSheet()->toArray())->flatten()->filter()->values()->all();
        $this->assertContains('MarcadorMantenimiento', $celdas);

        $libro = app(ExportacionService::class)->libro($this->personal->fresh(), self::SIN_FILTROS, $usuario);
        $celdas = collect($libro->getActiveSheet()->toArray())->flatten()->filter()->values()->all();
        $this->assertNotContains('MarcadorBacalar', $celdas);

        $this->actingAsTwoFactorVerified($usuario);
        $this->get(route('secciones.exportar', [$this->seccion, 'tabla' => $this->personal->id]))->assertSessionHasErrors('tabla');
    }

    public function test_edit_permission_uploads_and_deletes_documents_without_creating_imports(): void
    {
        $this->permisoSeccion($this->bacalar, 'editar');
        $deZona = $this->documento(null, 'de-zona.pdf');
        $this->actingAsTwoFactorVerified($this->cuentaDe($this->bacalar));

        $this->postJson(route('secciones.archivos.store', $this->seccion), [
            'archivos' => [UploadedFile::fake()->createWithContent('datos.csv', "a,b\n1,2\n")],
        ])->assertCreated();
        $this->delete(route('secciones.archivos.destroy', [$this->seccion, $deZona]))->assertRedirect();

        $subido = SeccionArchivo::query()->where('nombre_original', 'datos.csv')->sole();
        $this->assertSame($this->bacalar->id, $subido->estacion_id);
        $this->assertSoftDeleted($deZona);
        $this->assertSame(0, SeccionImportacion::query()->count());
        $this->assertSame(4, SeccionFila::query()->count(), 'la tabla no recibió filas del documento');
    }

    public function test_photos_are_served_only_from_rows_of_permitted_sheets(): void
    {
        $fotos = app(FotoService::class);
        $propia = str_repeat('a', 40).'.jpg';
        $ajena = str_repeat('b', 40).'.jpg';

        foreach ([$propia, $ajena] as $foto) {
            Storage::disk(config('secciones.disco'))->put($fotos->ruta($foto), 'jpg');
        }

        $this->filaMantenimiento->forceFill(['datos' => ['nombre' => 'MarcadorMantenimiento', 'foto' => $propia]])->save();
        $this->filaBacalar->forceFill(['datos' => ['nombre' => 'MarcadorBacalar', 'foto' => $ajena]])->save();
        $this->permisoHoja($this->bacalar, 'ver', $this->mantenimiento);
        $this->actingAsTwoFactorVerified($this->cuentaDe($this->bacalar));

        $this->get(route('secciones.fotos.show', [$this->seccion, $propia]))->assertOk();
        $this->get(route('secciones.fotos.show', [$this->seccion, $ajena]))->assertNotFound();

        $this->actingAsTwoFactorVerified($this->usuarioConRol('Edificio'));
        $this->get(route('secciones.fotos.show', [$this->seccion, $ajena]))->assertOk();
    }

    public function test_management_features_stay_closed_to_stations_even_with_edit(): void
    {
        $this->permisoSeccion($this->bacalar, 'editar');
        $this->actingAsTwoFactorVerified($this->cuentaDe($this->bacalar));
        $archivo = $this->documento($this->bacalar, 'propio.pdf');

        $cerradas = [
            $this->post(route('secciones.columnas.store', $this->seccion), ['nombre' => 'X', 'tipo' => 'texto']),
            $this->post(route('secciones.enlaces.store', $this->seccion), ['titulo' => 'X', 'url' => 'https://example.com']),
            $this->post(route('secciones.archivos.ia', [$this->seccion, $archivo])),
            $this->post(route('secciones.archivos.nube.vincular', [$this->seccion, $archivo]), ['enlace' => 'https://example.com']),
            $this->delete(route('secciones.tablas.destroy', [$this->seccion, $this->personal])),
            $this->put(route('secciones.update', $this->seccion), ['nombre' => 'Hack']),
            $this->put(route('secciones.estaciones.update', $this->seccion), ['todas' => 'editar']),
            $this->put(route('secciones.tablas.acceso.update', [$this->seccion, $this->personal]), ['estaciones' => [$this->chetumal->id => 'editar']]),
            $this->post(route('secciones.tablas.estaciones.aplicar', [$this->seccion, $this->personal]), ['columna' => 'nombre']),
        ];

        foreach ($cerradas as $respuesta) {
            $this->assertContains($respuesta->status(), [403, 404], 'estatus inesperado: '.$respuesta->status());
        }

        $this->assertNotSame('Hack', $this->seccion->fresh()->nombre);
        $this->assertSame(0, $this->personal->estacionesAprobadas()->count());
    }

    public function test_cargas_and_enlaces_tabs_are_not_rendered_for_stations(): void
    {
        $this->permisoSeccion($this->bacalar, 'editar');
        $this->actingAsTwoFactorVerified($this->cuentaDe($this->bacalar));

        $this->get(route('secciones.show', [$this->seccion, 'tab' => 'cargas']))
            ->assertOk()
            ->assertDontSee('Cargas (', false)
            ->assertDontSee('Enlaces (', false)
            ->assertDontSee('Permisos de estaciones');
    }

    public function test_the_assistant_is_closed_to_stations(): void
    {
        $this->actingAsTwoFactorVerified($this->cuentaDe($this->bacalar));

        $this->postJson(route('dashboard.asistente'), ['pregunta' => 'hola'])->assertForbidden();
    }

    public function test_an_account_without_station_sees_nothing(): void
    {
        $this->seccion->forceFill(['acceso_todas' => 'editar'])->save();
        $usuario = $this->usuarioConRol('Estación');
        $this->actingAsTwoFactorVerified($usuario);

        $this->get(route('secciones.show', $this->seccion))->assertNotFound();
        $this->post(route('secciones.filas.store', $this->seccion), ['datos' => ['nombre' => 'X']])->assertNotFound();
    }
}
