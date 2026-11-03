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
use Tests\Concerns\InteractsWithSecciones;
use Tests\TestCase;

/**
 * Una cuenta Estación solo ve y toca lo etiquetado con SU estación: filas,
 * documentos, fotos, exportación y conteos. Lo ajeno responde 404.
 */
class EstacionFugaDatosTest extends TestCase
{
    use InteractsWithSecciones, RefreshDatabase;

    private const SIN_FILTROS = ['q' => null, 'sort' => null, 'direction' => 'asc'];

    private Estacion $bacalar;

    private Estacion $chetumal;

    private Seccion $seccion;

    private SeccionTabla $tabla;

    private SeccionFila $filaA;

    private SeccionFila $filaB;

    private SeccionFila $filaSinEtiqueta;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->bacalar = Estacion::factory()->create(['nombre' => 'Bacalar', 'is_operativa' => true, 'orden' => 1]);
        $this->chetumal = Estacion::factory()->create(['nombre' => 'Chetumal', 'is_operativa' => true, 'orden' => 2]);

        $this->seccion = Seccion::factory()->create();
        $this->tabla = $this->tablaConColumnas($this->seccion, 'Personal', ['Nombre' => TipoColumna::Texto, 'Foto' => TipoColumna::Foto]);
        $this->filaA = $this->filaConEstacion(['nombre' => 'MarcadorBacalar'], $this->bacalar);
        $this->filaB = $this->filaConEstacion(['nombre' => 'MarcadorChetumal'], $this->chetumal);
        $this->filaSinEtiqueta = $this->filaConEstacion(['nombre' => 'MarcadorHuerfana'], null);
        $this->seccion->forceFill(['compartida_todas' => true])->save();
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function filaConEstacion(array $datos, ?Estacion $estacion): SeccionFila
    {
        $fila = $this->filaEnTabla($this->tabla, $datos);
        $fila->forceFill(['estacion_id' => $estacion?->id])->save();

        return $fila;
    }

    private function cuentaDe(Estacion $estacion): User
    {
        $usuario = $this->usuarioConRol('Estación');
        $usuario->forceFill(['estacion_id' => $estacion->id])->save();

        return $usuario;
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

    public function test_table_search_sort_and_counts_only_include_the_stations_rows(): void
    {
        $this->actingAsTwoFactorVerified($this->cuentaDe($this->bacalar));

        $this->get(route('secciones.show', $this->seccion))
            ->assertOk()
            ->assertSee('MarcadorBacalar')
            ->assertDontSee('MarcadorChetumal')
            ->assertDontSee('MarcadorHuerfana')
            ->assertSee('Tabla (1)', false);

        $this->get(route('secciones.show', [$this->seccion, 'q' => 'MarcadorChetumal']))
            ->assertOk()
            ->assertSee('Tabla (0)', false);

        $this->get(route('secciones.show', [$this->seccion, 'sort' => 'nombre', 'direction' => 'desc']))
            ->assertOk()
            ->assertDontSee('MarcadorHuerfana');
    }

    public function test_the_station_filter_cannot_be_used_to_see_other_stations(): void
    {
        $this->actingAsTwoFactorVerified($this->cuentaDe($this->bacalar));

        $this->get(route('secciones.show', [$this->seccion, 'estacion' => $this->chetumal->id]))
            ->assertOk()
            ->assertDontSee('MarcadorChetumal');
        $this->get(route('secciones.show', [$this->seccion, 'estacion' => 'sin']))
            ->assertOk()
            ->assertDontSee('MarcadorHuerfana');
    }

    public function test_zone_users_see_every_row_including_untagged_ones(): void
    {
        $this->actingAsTwoFactorVerified($this->usuarioConRol('Edificio'));

        $this->get(route('secciones.show', $this->seccion))
            ->assertOk()
            ->assertSee('MarcadorBacalar')
            ->assertSee('MarcadorChetumal')
            ->assertSee('MarcadorHuerfana');
    }

    public function test_export_only_contains_the_stations_rows(): void
    {
        $usuario = $this->cuentaDe($this->bacalar);

        $libro = app(ExportacionService::class)->libro($this->tabla->fresh(), self::SIN_FILTROS, $usuario);
        $celdas = collect($libro->getActiveSheet()->toArray())->flatten()->filter()->values()->all();

        $this->assertContains('MarcadorBacalar', $celdas);
        $this->assertNotContains('MarcadorChetumal', $celdas);
        $this->assertNotContains('MarcadorHuerfana', $celdas);
    }

    public function test_rows_created_by_a_station_are_tagged_with_its_own_station_whatever_is_sent(): void
    {
        $this->actingAsTwoFactorVerified($this->cuentaDe($this->bacalar));

        $this->post(route('secciones.filas.store', $this->seccion), [
            'tabla' => $this->tabla->id,
            'datos' => ['nombre' => 'NuevaDeBacalar'],
            'estacion_id' => $this->chetumal->id,
        ])->assertRedirect();

        $this->assertSame($this->bacalar->id, SeccionFila::query()->where('texto_busqueda', 'like', '%nuevadebacalar%')->value('estacion_id'));
    }

    public function test_a_station_edits_its_rows_but_gets_404_for_other_stations_and_untagged_rows(): void
    {
        $this->actingAsTwoFactorVerified($this->cuentaDe($this->bacalar));

        $this->put(route('secciones.filas.update', [$this->seccion, $this->filaA]), ['datos' => ['nombre' => 'Editada']])->assertRedirect();
        $this->assertSame('Editada', $this->filaA->fresh()->datos['nombre']);

        $this->put(route('secciones.filas.update', [$this->seccion, $this->filaB]), ['datos' => ['nombre' => 'Hack']])->assertNotFound();
        $this->put(route('secciones.filas.update', [$this->seccion, $this->filaSinEtiqueta]), ['datos' => ['nombre' => 'Hack']])->assertNotFound();
        $this->assertSame('MarcadorChetumal', $this->filaB->fresh()->datos['nombre']);
    }

    public function test_a_station_cannot_move_its_row_to_another_station(): void
    {
        $this->actingAsTwoFactorVerified($this->cuentaDe($this->bacalar));

        $this->put(route('secciones.filas.update', [$this->seccion, $this->filaA]), [
            'datos' => ['nombre' => 'Sigue'],
            'estacion_id' => $this->chetumal->id,
        ])->assertRedirect();

        $this->assertSame($this->bacalar->id, $this->filaA->fresh()->estacion_id);
    }

    public function test_a_station_cannot_delete_rows(): void
    {
        $this->actingAsTwoFactorVerified($this->cuentaDe($this->bacalar));

        $this->delete(route('secciones.filas.destroy', [$this->seccion, $this->filaA]))->assertForbidden();
        $this->delete(route('secciones.filas.destroy', [$this->seccion, $this->filaB]))->assertNotFound();
        $this->assertNull($this->filaA->fresh()->deleted_at);
        $this->assertNull($this->filaB->fresh()->deleted_at);
    }

    public function test_documents_are_listed_and_served_only_for_the_stations_own(): void
    {
        $propio = $this->documento($this->bacalar, 'propio.pdf');
        $ajeno = $this->documento($this->chetumal, 'ajeno.pdf');
        $sinDueno = $this->documento(null, 'huerfano.pdf');
        $this->actingAsTwoFactorVerified($this->cuentaDe($this->bacalar));

        $this->get(route('secciones.show', [$this->seccion, 'tab' => 'documentos']))
            ->assertOk()
            ->assertSee('propio.pdf')
            ->assertDontSee('ajeno.pdf')
            ->assertDontSee('huerfano.pdf');

        $this->get(route('secciones.archivos.show', [$this->seccion, $propio]))->assertOk();
        $this->get(route('secciones.archivos.show', [$this->seccion, $ajeno]))->assertNotFound();
        $this->get(route('secciones.archivos.show', [$this->seccion, $sinDueno]))->assertNotFound();
        $this->getJson(route('secciones.archivos.vista-previa', [$this->seccion, $ajeno]))->assertNotFound();
    }

    public function test_a_station_cannot_delete_documents(): void
    {
        $propio = $this->documento($this->bacalar, 'propio.pdf');
        $this->actingAsTwoFactorVerified($this->cuentaDe($this->bacalar));

        $this->delete(route('secciones.archivos.destroy', [$this->seccion, $propio]))->assertForbidden();
        $this->assertNull($propio->fresh()->deleted_at);
    }

    public function test_a_document_uploaded_by_a_station_is_tagged_and_never_creates_an_import(): void
    {
        $this->actingAsTwoFactorVerified($this->cuentaDe($this->bacalar));

        $this->postJson(route('secciones.archivos.store', $this->seccion), [
            'archivos' => [UploadedFile::fake()->createWithContent('datos.csv', "a,b\n1,2\n")],
        ])->assertCreated();

        $archivo = SeccionArchivo::query()->where('nombre_original', 'datos.csv')->sole();
        $this->assertSame($this->bacalar->id, $archivo->estacion_id);
        $this->assertSame(0, SeccionImportacion::query()->count());
        $this->assertSame(3, SeccionFila::query()->count(), 'la tabla no recibió filas del documento');
    }

    public function test_a_station_cannot_attach_documents_to_other_stations_rows(): void
    {
        $this->actingAsTwoFactorVerified($this->cuentaDe($this->bacalar));

        $this->postJson(route('secciones.archivos.store', $this->seccion), [
            'archivos' => [UploadedFile::fake()->createWithContent('datos.csv', "a\n1\n")],
            'seccion_fila_id' => $this->filaB->id,
        ])->assertUnprocessable();

        $this->assertSame(0, SeccionArchivo::query()->count());
    }

    public function test_photos_are_served_only_when_they_belong_to_the_stations_rows(): void
    {
        $fotos = app(FotoService::class);
        $propia = str_repeat('a', 40).'.jpg';
        $ajena = str_repeat('b', 40).'.jpg';

        foreach ([$propia, $ajena] as $foto) {
            Storage::disk(config('secciones.disco'))->put($fotos->ruta($foto), 'jpg');
        }

        $this->filaA->forceFill(['datos' => ['nombre' => 'MarcadorBacalar', 'foto' => $propia]])->save();
        $this->filaB->forceFill(['datos' => ['nombre' => 'MarcadorChetumal', 'foto' => $ajena]])->save();
        $this->actingAsTwoFactorVerified($this->cuentaDe($this->bacalar));

        $this->get(route('secciones.fotos.show', [$this->seccion, $propia]))->assertOk();
        $this->get(route('secciones.fotos.show', [$this->seccion, $ajena]))->assertNotFound();

        $this->actingAsTwoFactorVerified($this->usuarioConRol('Edificio'));
        $this->get(route('secciones.fotos.show', [$this->seccion, $ajena]))->assertOk();
    }

    public function test_management_features_are_closed_to_stations(): void
    {
        $this->actingAsTwoFactorVerified($this->cuentaDe($this->bacalar));
        $archivo = $this->documento($this->bacalar, 'propio.pdf');

        $cerradas = [
            $this->post(route('secciones.columnas.store', $this->seccion), ['nombre' => 'X', 'tipo' => 'texto']),
            $this->post(route('secciones.enlaces.store', $this->seccion), ['titulo' => 'X', 'url' => 'https://example.com']),
            $this->post(route('secciones.archivos.ia', [$this->seccion, $archivo])),
            $this->post(route('secciones.archivos.nube.vincular', [$this->seccion, $archivo]), ['enlace' => 'https://example.com']),
            $this->delete(route('secciones.tablas.destroy', [$this->seccion, $this->tabla])),
            $this->put(route('secciones.update', $this->seccion), ['nombre' => 'Hack']),
            $this->put(route('secciones.estaciones.update', $this->seccion), ['todas' => '1']),
            $this->post(route('secciones.tablas.estaciones.aplicar', [$this->seccion, $this->tabla]), ['columna' => 'nombre']),
        ];

        foreach ($cerradas as $respuesta) {
            $this->assertContains($respuesta->status(), [403, 404], 'estatus inesperado: '.$respuesta->status());
        }

        $this->assertNotSame('Hack', $this->seccion->fresh()->nombre);
    }

    public function test_cargas_and_enlaces_tabs_are_not_rendered_for_stations(): void
    {
        $this->actingAsTwoFactorVerified($this->cuentaDe($this->bacalar));

        $this->get(route('secciones.show', [$this->seccion, 'tab' => 'cargas']))
            ->assertOk()
            ->assertDontSee('Cargas (', false)
            ->assertDontSee('Enlaces (', false);
    }

    public function test_the_assistant_is_closed_to_stations(): void
    {
        $this->actingAsTwoFactorVerified($this->cuentaDe($this->bacalar));

        $this->postJson(route('dashboard.asistente'), ['pregunta' => 'hola'])->assertForbidden();
    }

    public function test_an_account_without_station_sees_nothing(): void
    {
        $usuario = $this->usuarioConRol('Estación');
        $this->actingAsTwoFactorVerified($usuario);

        $this->get(route('secciones.show', $this->seccion))->assertNotFound();
        $this->post(route('secciones.filas.store', $this->seccion), ['datos' => ['nombre' => 'X']])->assertNotFound();
    }
}
