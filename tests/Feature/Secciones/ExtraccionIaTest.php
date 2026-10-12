<?php

namespace Tests\Feature\Secciones;

use App\Models\Seccion;
use App\Models\SeccionArchivo;
use App\Models\SeccionImportacion;
use App\Services\Asistente\ModeloLenguaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithSecciones;
use Tests\Fakes\ModeloLenguajeFalso;
use Tests\TestCase;

/**
 * "Extraer con IA": el documento va a Claude y lo devuelto entra a la
 * misma vista previa que cualquier carga. Nunca se llama a la API real.
 */
class ExtraccionIaTest extends TestCase
{
    use InteractsWithSecciones, RefreshDatabase;

    private ModeloLenguajeFalso $modelo;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->modelo = new ModeloLenguajeFalso;
        $this->app->instance(ModeloLenguaje::class, $this->modelo);
    }

    private function pdfSubido(Seccion $seccion): SeccionArchivo
    {
        $this->postJson(route('secciones.archivos.store', $seccion), [
            'archivos' => [UploadedFile::fake()->createWithContent('escaneo.pdf', "%PDF-1.4\n%fake\n")],
        ])->assertCreated();

        return SeccionArchivo::query()->sole();
    }

    public function test_extracted_tables_open_the_regular_preview(): void
    {
        $usuario = $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $archivo = $this->pdfSubido($seccion);
        $this->modelo->encolarTexto(json_encode(['tablas' => [[
            'nombre' => 'Extintores',
            'encabezados' => ['Ubicación', 'Vence'],
            'filas' => [['Andén A', '2026-12-01'], ['Taquilla', '']],
        ]]]));

        $respuesta = $this->post(route('secciones.archivos.ia', [$seccion, $archivo]));

        $importacion = SeccionImportacion::query()->sole();
        $respuesta->assertRedirect(route('secciones.importaciones.show', [$seccion, $importacion]));
        $this->assertSame(SeccionImportacion::ORIGEN_IA, $importacion->origen);
        $this->assertSame([['Ubicación', 'Vence'], ['Andén A', '2026-12-01'], ['Taquilla', null]], $importacion->tablas[0]['filas']);
        $this->assertTrue(Activity::query()->where('event', 'extraccion_ia')->where('causer_id', $usuario->id)->exists());

        $peticion = $this->modelo->peticiones[0];
        $this->assertSame('document', $peticion['messages'][0]['content'][0]['type']);
        $this->assertSame('json_schema', $peticion['outputConfig']['format']['type']);
        $this->assertDatabaseCount('seccion_filas', 0);
    }

    public function test_a_refusal_is_reported_without_creating_a_load(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $archivo = $this->pdfSubido($seccion);
        $this->modelo->encolarTexto('', 'refusal');

        $this->post(route('secciones.archivos.ia', [$seccion, $archivo]))->assertSessionHasErrors('ia');

        $this->assertDatabaseCount('seccion_importaciones', 0);
    }

    public function test_malformed_answers_are_rejected(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $archivo = $this->pdfSubido($seccion);
        $this->modelo->encolarTexto('no es json');

        $this->post(route('secciones.archivos.ia', [$seccion, $archivo]))->assertSessionHasErrors('ia');
    }

    public function test_ai_is_unavailable_without_an_api_key(): void
    {
        $this->app->instance(ModeloLenguaje::class, new ModeloLenguajeFalso(disponible: false));
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $archivo = $this->pdfSubido($seccion);

        $this->post(route('secciones.archivos.ia', [$seccion, $archivo]))->assertSessionHasErrors('ia');
        $this->get(route('secciones.show', [$seccion, 'tab' => 'documentos']))->assertDontSee('Extraer con IA');
    }

    public function test_estacion_role_cannot_use_ai_extraction(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $archivo = $this->pdfSubido($seccion);

        $this->actingAsTwoFactorVerified($this->usuarioConRol('Estación'));

        $this->post(route('secciones.archivos.ia', [$seccion, $archivo]))->assertForbidden();
        $this->assertSame([], $this->modelo->peticiones);
    }
}
