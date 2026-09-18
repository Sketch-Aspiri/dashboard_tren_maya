<?php

namespace Tests\Feature\Documentos;

use App\Enums\EmpleadoEstatus;
use App\Enums\EstatusAsistencia;
use App\Enums\TipoDocumentoAsistencia;
use App\Enums\TipoPlaza;
use App\Models\DocumentoAsistencia;
use App\Models\Empleado;
use App\Models\Estacion;
use App\Models\RegistroDiario;
use App\Models\User;
use App\Services\Documentos\ConvertidorPdf;
use App\Services\Documentos\NumeroOficioService;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;
use ZipArchive;

/**
 * Zone-wide oficio through the real HTTP stack (real .docx template, fake
 * PDF converter, faked disk).
 */
class OficioZonaTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Storage::fake('local');

        $this->app->instance(ConvertidorPdf::class, new class implements ConvertidorPdf
        {
            public function convertir(string $docx, string $pdfDestino): void
            {
                file_put_contents($pdfDestino, '%PDF-1.4 fake');
            }
        });
    }

    private function usuario(string $rol, ?Estacion $estacion = null): User
    {
        $user = User::factory()->create(['estacion_id' => $estacion?->id]);
        $user->assignRole($rol);

        return $user;
    }

    /** Captures today for one person of the given station. */
    private function capturado(Estacion $estacion, string $nombre, TipoPlaza $tipo = TipoPlaza::Permanente, int $orden = 1): Empleado
    {
        $empleado = Empleado::factory()->create([
            'estacion_id' => $estacion->id,
            'estatus' => EmpleadoEstatus::Activo->value,
            'nombre_completo' => $nombre,
            'tipo_plaza' => $tipo->value,
            'orden_oficio' => $orden,
        ]);
        RegistroDiario::factory()->create([
            'empleado_id' => $empleado->id,
            'estacion_id' => $estacion->id,
            'fecha' => CarbonImmutable::today()->toDateString(),
            'estatus' => EstatusAsistencia::Presente->value,
        ]);

        return $empleado;
    }

    /** Two stations (one being the zone office) with everything captured. */
    private function zonaCompleta(): void
    {
        $this->capturado(Estacion::factory()->create(['nombre' => 'Bacalar', 'is_operativa' => true]), 'Persona Bacalar', orden: 1);
        $this->capturado(Estacion::factory()->create(['nombre' => 'Edificio Zonal Este', 'is_operativa' => false]), 'Persona Zonal', TipoPlaza::Eventual, 2);
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'fecha' => CarbonImmutable::today()->toDateString(),
            'numero_oficio' => 'TM/UAI/CGGIF/DGTZO/1735',
            'firmante' => 'subgerente',
        ], $override);
    }

    private function textoDelDocx(DocumentoAsistencia $documento): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'zona').'.docx';
        file_put_contents($ruta, Storage::disk('local')->get($documento->docx_path));
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($ruta) === true, 'El .docx de zona no es un zip válido');
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($ruta);

        return html_entity_decode(strip_tags(str_replace(['</w:p>', '</w:tc>'], ' ', $xml)), ENT_QUOTES | ENT_XML1);
    }

    // --- Generación ---------------------------------------------------------

    public function test_jefe_de_zona_genera_el_oficio_cuando_todas_capturaron(): void
    {
        $this->zonaCompleta();
        $this->actingAsTwoFactorVerified($this->usuario('Jefe de Zona'));

        $response = $this->post(route('asistencia.zona.oficio.store'), $this->payload());

        $response->assertRedirect();
        $documento = DocumentoAsistencia::firstOrFail();
        $this->assertSame(TipoDocumentoAsistencia::Zona, $documento->tipo);
        $this->assertNull($documento->estacion_id);
        Storage::disk('local')->assertExists($documento->docx_path);
        Storage::disk('local')->assertExists($documento->pdf_path);
    }

    public function test_el_docx_de_zona_incluye_folio_personal_y_sin_marcadores_pendientes(): void
    {
        $this->zonaCompleta();
        $this->actingAsTwoFactorVerified($this->usuario('Administrador'));

        $this->post(route('asistencia.zona.oficio.store'), $this->payload());

        $texto = $this->textoDelDocx(DocumentoAsistencia::firstOrFail());
        $this->assertStringContainsString('TM/UAI/CGGIF/DGTZO/1735', $texto);
        $this->assertStringContainsString('PERSONA BACALAR', $texto);
        $this->assertStringContainsString('PERSONA ZONAL', $texto);
        $this->assertStringNotContainsString('${', $texto);
    }

    public function test_firmar_por_suplencia_agrega_el_parrafo_y_marca_el_registro(): void
    {
        $this->zonaCompleta();
        $this->actingAsTwoFactorVerified($this->usuario('Jefe de Zona'));

        $this->post(route('asistencia.zona.oficio.store'), $this->payload(['firmante' => 'subgerente']));

        $documento = DocumentoAsistencia::firstOrFail();
        $this->assertTrue($documento->es_suplencia);
        $this->assertStringContainsString('por ausencia temporal de la Dirección', $this->textoDelDocx($documento));
        $this->assertSame('Mtro. Jesús Alberto Tec Pimentel', $documento->firmante_nombre);
    }

    public function test_firmar_el_director_no_incluye_el_parrafo_de_suplencia(): void
    {
        $this->zonaCompleta();
        $this->actingAsTwoFactorVerified($this->usuario('Jefe de Zona'));

        $this->post(route('asistencia.zona.oficio.store'), $this->payload(['firmante' => 'director']));

        $documento = DocumentoAsistencia::firstOrFail();
        $this->assertFalse($documento->es_suplencia);
        $this->assertStringNotContainsString('por ausencia temporal de la Dirección', $this->textoDelDocx($documento));
    }

    public function test_regenerar_conserva_fila_y_folio(): void
    {
        $this->zonaCompleta();
        $this->actingAsTwoFactorVerified($this->usuario('Administrador'));

        $this->post(route('asistencia.zona.oficio.store'), $this->payload());
        $primero = DocumentoAsistencia::firstOrFail();
        $this->post(route('asistencia.zona.oficio.store'), $this->payload(['firmante' => 'director']));

        $this->assertSame(1, DocumentoAsistencia::count());
        $this->assertSame($primero->id, DocumentoAsistencia::firstOrFail()->id);
        $this->assertFalse(DocumentoAsistencia::firstOrFail()->es_suplencia);
    }

    // --- Bloqueo por captura pendiente ------------------------------------------------

    public function test_no_se_genera_si_falta_una_estacion_y_se_nombra_cual(): void
    {
        $this->zonaCompleta();
        $pendiente = Estacion::factory()->create(['nombre' => 'Kohunlich', 'is_operativa' => true]);
        Empleado::factory()->create(['estacion_id' => $pendiente->id, 'estatus' => EmpleadoEstatus::Activo->value]);
        $this->actingAsTwoFactorVerified($this->usuario('Jefe de Zona'));

        $response = $this->post(route('asistencia.zona.oficio.store'), $this->payload());

        $response->assertSessionHasErrors('oficio');
        $this->assertStringContainsString('Kohunlich', session('errors')->first('oficio'));
        $this->assertSame(0, DocumentoAsistencia::count());
    }

    public function test_el_edificio_zonal_este_tambien_cuenta_como_pendiente(): void
    {
        $this->capturado(Estacion::factory()->create(['nombre' => 'Bacalar', 'is_operativa' => true]), 'Persona Bacalar');
        $eze = Estacion::factory()->create(['nombre' => 'Edificio Zonal Este', 'is_operativa' => false]);
        Empleado::factory()->create(['estacion_id' => $eze->id, 'estatus' => EmpleadoEstatus::Activo->value]);
        $this->actingAsTwoFactorVerified($this->usuario('Administrador'));

        $this->post(route('asistencia.zona.oficio.store'), $this->payload())
            ->assertSessionHasErrors('oficio');
    }

    public function test_estaciones_sin_personal_activo_no_bloquean(): void
    {
        $this->zonaCompleta();
        Estacion::factory()->create(['nombre' => 'Vacia', 'is_operativa' => true]);
        $this->actingAsTwoFactorVerified($this->usuario('Jefe de Zona'));

        $this->post(route('asistencia.zona.oficio.store'), $this->payload())->assertSessionHasNoErrors();

        $this->assertSame(1, DocumentoAsistencia::count());
    }

    // --- Autorización y validación ------------------------------------------------------

    public function test_invitado_es_redirigido_al_login(): void
    {
        $this->post(route('asistencia.zona.oficio.store'), $this->payload())->assertRedirect(route('login'));
    }

    public function test_una_cuenta_de_estacion_no_puede_generar_el_oficio_de_zona(): void
    {
        $estacion = Estacion::factory()->create();
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $estacion));

        $this->post(route('asistencia.zona.oficio.store'), $this->payload())->assertForbidden();
    }

    public function test_firmante_desconocido_y_folio_invalido_son_rechazados(): void
    {
        $this->zonaCompleta();
        $this->actingAsTwoFactorVerified($this->usuario('Jefe de Zona'));

        $this->post(route('asistencia.zona.oficio.store'), $this->payload([
            'firmante' => 'cualquiera',
            'numero_oficio' => '<script>',
        ]))->assertSessionHasErrors(['firmante', 'numero_oficio']);

        $this->assertSame(0, DocumentoAsistencia::count());
    }

    public function test_el_folio_de_zona_no_puede_repetirse(): void
    {
        $this->zonaCompleta();
        DocumentoAsistencia::factory()->create(['numero_oficio' => 'TM/UAI/CGGIF/DGTZO/1735']);
        $this->actingAsTwoFactorVerified($this->usuario('Jefe de Zona'));

        $this->post(route('asistencia.zona.oficio.store'), $this->payload())
            ->assertSessionHasErrors('numero_oficio');
    }

    // --- Tablero ------------------------------------------------------------------------

    public function test_el_tablero_muestra_el_formulario_cuando_todo_esta_capturado(): void
    {
        $this->zonaCompleta();
        $this->actingAsTwoFactorVerified($this->usuario('Jefe de Zona'));

        $this->get(route('asistencia.zona.index'))
            ->assertOk()
            ->assertSee('Generar oficio de zona')
            ->assertSee('Subgerente de Operación y Enlace');
    }

    public function test_el_tablero_lista_las_estaciones_pendientes(): void
    {
        $pendiente = Estacion::factory()->create(['nombre' => 'Kohunlich', 'is_operativa' => true]);
        Empleado::factory()->create(['estacion_id' => $pendiente->id, 'estatus' => EmpleadoEstatus::Activo->value]);
        $this->actingAsTwoFactorVerified($this->usuario('Jefe de Zona'));

        $this->get(route('asistencia.zona.index'))
            ->assertOk()
            ->assertSee('Aún faltan por capturar')
            ->assertDontSee('Generar oficio de zona');
    }

    // --- Folio sugerido --------------------------------------------------------------------

    public function test_folio_de_zona_continua_desde_el_ultimo_generado(): void
    {
        DocumentoAsistencia::factory()->zona()->create(['numero_oficio' => 'TM/UAI/CGGIF/DGTZO/1735']);

        $this->assertSame('TM/UAI/CGGIF/DGTZO/1736', app(NumeroOficioService::class)->sugerirParaZona());
    }

    public function test_folio_de_zona_sin_historial_solo_sugiere_el_prefijo(): void
    {
        $this->assertSame('TM/UAI/CGGIF/DGTZO/', app(NumeroOficioService::class)->sugerirParaZona());
    }

    public function test_folio_de_estacion_continua_el_consecutivo_y_usa_la_clave_conocida(): void
    {
        $estacion = Estacion::factory()->create(['nombre' => 'Puerto Morelos']);
        DocumentoAsistencia::factory()->create([
            'estacion_id' => $estacion->id,
            'fecha' => '2026-09-17',
            'numero_oficio' => 'T.M.M./RR.HH./23PMO/00340/2026',
        ]);

        $sugerido = app(NumeroOficioService::class)->sugerirParaEstacion($estacion, CarbonImmutable::parse('2026-09-18'));

        $this->assertSame('T.M.M./RR.HH./23PMO/00341/2026', $sugerido);
    }

    public function test_folio_de_estacion_sin_clave_conocida_omite_la_clave(): void
    {
        $estacion = Estacion::factory()->create(['nombre' => 'Bacalar']);

        $sugerido = app(NumeroOficioService::class)->sugerirParaEstacion($estacion, CarbonImmutable::parse('2026-09-18'));

        $this->assertSame('T.M.M./RR.HH./00001/2026', $sugerido);
    }
}
