<?php

namespace Tests\Feature\Documentos;

use App\Enums\EmpleadoEstatus;
use App\Enums\EstatusAsistencia;
use App\Enums\TipoDocumentoAsistencia;
use App\Models\DocumentoAsistencia;
use App\Models\Empleado;
use App\Models\Estacion;
use App\Models\RegistroDiario;
use App\Models\User;
use App\Services\Documentos\ConversionPdfException;
use App\Services\Documentos\ConvertidorPdf;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Generation + download of the station oficio through the real HTTP stack.
 * LibreOffice is replaced by a fake converter (its own test covers the real
 * one) and files go to a faked disk.
 */
class OficioEstacionTest extends TestCase
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

    /** A station whose whole roster (2 people) already captured today. */
    private function estacionCapturada(): Estacion
    {
        $estacion = Estacion::factory()->create(['nombre' => 'Puerto Morelos', 'is_operativa' => true]);

        foreach (['Ana Dummy', 'Beto Dummy'] as $nombre) {
            $empleado = Empleado::factory()->create([
                'estacion_id' => $estacion->id,
                'estatus' => EmpleadoEstatus::Activo->value,
                'nombre_completo' => $nombre,
            ]);
            RegistroDiario::factory()->create([
                'empleado_id' => $empleado->id,
                'estacion_id' => $estacion->id,
                'fecha' => CarbonImmutable::today()->toDateString(),
                'estatus' => EstatusAsistencia::Presente->value,
            ]);
        }

        return $estacion;
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'fecha' => CarbonImmutable::today()->toDateString(),
            'numero_oficio' => 'T.M.M./RR.HH./23PMO/00001/2026',
            'firmante_cargo' => 'Gerente de Estación Puerto Morelos',
            'firmante_nombre' => 'Lic. Firmante Dummy',
        ], $override);
    }

    // --- Generación ---------------------------------------------------------

    public function test_estacion_genera_su_oficio_y_se_guardan_docx_y_pdf(): void
    {
        $estacion = $this->estacionCapturada();
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $estacion));

        $response = $this->post(route('asistencia.captura.oficio.store'), $this->payload());

        $response->assertRedirect(route('asistencia.captura.index'));
        $documento = DocumentoAsistencia::firstOrFail();
        $this->assertSame(TipoDocumentoAsistencia::Estacion, $documento->tipo);
        $this->assertSame($estacion->id, $documento->estacion_id);
        $this->assertSame('T.M.M./RR.HH./23PMO/00001/2026', $documento->numero_oficio);
        Storage::disk('local')->assertExists($documento->docx_path);
        Storage::disk('local')->assertExists($documento->pdf_path);
    }

    public function test_el_docx_guardado_es_un_word_valido_con_el_folio(): void
    {
        $estacion = $this->estacionCapturada();
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $estacion));

        $this->post(route('asistencia.captura.oficio.store'), $this->payload());

        $documento = DocumentoAsistencia::firstOrFail();
        $ruta = tempnam(sys_get_temp_dir(), 'chk').'.docx';
        file_put_contents($ruta, Storage::disk('local')->get($documento->docx_path));
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($ruta) === true);
        $this->assertStringContainsString('T.M.M./RR.HH./23PMO/00001/2026', (string) $zip->getFromName('word/document.xml'));
        $zip->close();
        unlink($ruta);
    }

    public function test_regenerar_conserva_la_fila_y_el_folio_y_sobrescribe_archivos(): void
    {
        $estacion = $this->estacionCapturada();
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $estacion));

        $this->post(route('asistencia.captura.oficio.store'), $this->payload());
        $primero = DocumentoAsistencia::firstOrFail();
        $this->post(route('asistencia.captura.oficio.store'), $this->payload(['firmante_nombre' => 'Otro Firmante']));

        $this->assertSame(1, DocumentoAsistencia::count());
        $segundo = DocumentoAsistencia::firstOrFail();
        $this->assertSame($primero->id, $segundo->id);
        $this->assertSame($primero->docx_path, $segundo->docx_path);
        $this->assertSame('Otro Firmante', $segundo->firmante_nombre);
    }

    public function test_generar_queda_registrado_en_la_bitacora(): void
    {
        $estacion = $this->estacionCapturada();
        $usuario = $this->actingAsTwoFactorVerified($this->usuario('Estación', $estacion));

        $this->post(route('asistencia.captura.oficio.store'), $this->payload());

        $this->assertTrue(
            Activity::query()
                ->where('log_name', 'documento_asistencia')
                ->where('event', 'created')
                ->where('causer_id', $usuario->id)
                ->exists(),
        );
    }

    public function test_no_se_puede_generar_si_la_estacion_no_termino_de_capturar(): void
    {
        $estacion = Estacion::factory()->create(['is_operativa' => true]);
        Empleado::factory()->create(['estacion_id' => $estacion->id, 'estatus' => EmpleadoEstatus::Activo->value]);
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $estacion));

        $response = $this->post(route('asistencia.captura.oficio.store'), $this->payload());

        $response->assertSessionHasErrors('oficio');
        $this->assertSame(0, DocumentoAsistencia::count());
    }

    public function test_falla_controlada_si_la_conversion_a_pdf_falla_y_no_deja_registro(): void
    {
        $this->app->instance(ConvertidorPdf::class, new class implements ConvertidorPdf
        {
            public function convertir(string $docx, string $pdfDestino): void
            {
                throw new ConversionPdfException('No se pudo generar el PDF.');
            }
        });
        $estacion = $this->estacionCapturada();
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $estacion));

        $response = $this->post(route('asistencia.captura.oficio.store'), $this->payload());

        $response->assertSessionHasErrors(['oficio' => 'No se pudo generar el PDF.']);
        $this->assertSame(0, DocumentoAsistencia::count());
    }

    // --- Autorización ---------------------------------------------------------

    public function test_invitado_es_redirigido_al_login(): void
    {
        $this->post(route('asistencia.captura.oficio.store'), $this->payload())->assertRedirect(route('login'));
    }

    public function test_estacion_ignora_el_id_de_otra_estacion_y_genera_el_suyo(): void
    {
        $propia = $this->estacionCapturada();
        $ajena = Estacion::factory()->create(['is_operativa' => true]);
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $propia));

        $this->post(route('asistencia.captura.oficio.store'), $this->payload(['estacion' => $ajena->id]));

        $this->assertSame($propia->id, DocumentoAsistencia::firstOrFail()->estacion_id);
    }

    public function test_estacion_no_puede_generar_de_una_fecha_pasada(): void
    {
        $estacion = $this->estacionCapturada();
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $estacion));

        $response = $this->post(route('asistencia.captura.oficio.store'), $this->payload([
            'fecha' => CarbonImmutable::yesterday()->toDateString(),
        ]));

        $response->assertForbidden();
        $this->assertSame(0, DocumentoAsistencia::count());
    }

    public function test_jefe_de_zona_no_puede_generar_oficios_de_estacion(): void
    {
        $estacion = $this->estacionCapturada();
        $this->actingAsTwoFactorVerified($this->usuario('Jefe de Zona'));

        $this->post(route('asistencia.captura.oficio.store'), $this->payload(['estacion' => $estacion->id]))
            ->assertForbidden();
    }

    public function test_administrador_puede_generar_de_cualquier_estacion_y_fecha(): void
    {
        $estacion = $this->estacionCapturada();
        $ayer = CarbonImmutable::yesterday();
        $empleado = Empleado::where('estacion_id', $estacion->id)->get();
        foreach ($empleado as $e) {
            RegistroDiario::factory()->create(['empleado_id' => $e->id, 'estacion_id' => $estacion->id, 'fecha' => $ayer->toDateString()]);
        }
        $this->actingAsTwoFactorVerified($this->usuario('Administrador'));

        $this->post(route('asistencia.captura.oficio.store'), $this->payload([
            'estacion' => $estacion->id,
            'fecha' => $ayer->toDateString(),
        ]))->assertRedirect();

        $this->assertSame($ayer->toDateString(), DocumentoAsistencia::firstOrFail()->fecha->toDateString());
    }

    // --- Validación --------------------------------------------------------------

    public function test_valida_campos_requeridos_y_formato_del_folio(): void
    {
        $estacion = $this->estacionCapturada();
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $estacion));

        $this->post(route('asistencia.captura.oficio.store'), $this->payload([
            'numero_oficio' => '../../etc/passwd;<script>',
            'firmante_nombre' => '',
        ]))->assertSessionHasErrors(['numero_oficio', 'firmante_nombre']);

        $this->assertSame(0, DocumentoAsistencia::count());
    }

    public function test_el_folio_no_puede_repetirse_en_otro_oficio(): void
    {
        $estacion = $this->estacionCapturada();
        DocumentoAsistencia::factory()->create(['numero_oficio' => 'T.M.M./RR.HH./23PMO/00001/2026']);
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $estacion));

        $this->post(route('asistencia.captura.oficio.store'), $this->payload())
            ->assertSessionHasErrors('numero_oficio');
    }

    // --- Descarga ------------------------------------------------------------------

    private function documentoConArchivos(Estacion $estacion): DocumentoAsistencia
    {
        $documento = DocumentoAsistencia::factory()->create(['estacion_id' => $estacion->id]);
        Storage::disk('local')->put($documento->docx_path, 'docx-bytes');
        Storage::disk('local')->put($documento->pdf_path, '%PDF fake');

        return $documento;
    }

    public function test_estacion_descarga_su_propio_oficio_en_ambos_formatos_y_queda_en_bitacora(): void
    {
        $estacion = Estacion::factory()->create();
        $documento = $this->documentoConArchivos($estacion);
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $estacion));

        $docx = $this->get(route('asistencia.documentos.download', [$documento, 'docx']));
        $pdf = $this->get(route('asistencia.documentos.download', [$documento, 'pdf']));

        $docx->assertOk()->assertDownload();
        $pdf->assertOk()->assertDownload();
        $this->assertSame(2, Activity::where('log_name', 'documento_asistencia')->where('description', 'descargado')->count());
    }

    public function test_estacion_no_puede_descargar_el_oficio_de_otra_estacion(): void
    {
        $propia = Estacion::factory()->create();
        $ajena = Estacion::factory()->create();
        $documento = $this->documentoConArchivos($ajena);
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $propia));

        $this->get(route('asistencia.documentos.download', [$documento, 'pdf']))->assertNotFound();
    }

    public function test_jefe_de_zona_descarga_cualquier_oficio(): void
    {
        $documento = $this->documentoConArchivos(Estacion::factory()->create());
        $this->actingAsTwoFactorVerified($this->usuario('Jefe de Zona'));

        $this->get(route('asistencia.documentos.download', [$documento, 'pdf']))->assertOk();
    }

    public function test_formato_no_permitido_devuelve_404(): void
    {
        $documento = $this->documentoConArchivos(Estacion::factory()->create());
        $this->actingAsTwoFactorVerified($this->usuario('Administrador'));

        $this->get('/asistencia/documentos/'.$documento->id.'/exe')->assertNotFound();
    }

    public function test_archivo_faltante_en_disco_devuelve_404(): void
    {
        $documento = DocumentoAsistencia::factory()->create();
        $this->actingAsTwoFactorVerified($this->usuario('Administrador'));

        $this->get(route('asistencia.documentos.download', [$documento, 'pdf']))->assertNotFound();
    }

    // --- Pantalla de captura ------------------------------------------------------------

    public function test_la_pantalla_de_captura_ofrece_generar_solo_cuando_ya_capturo_todo(): void
    {
        $estacion = $this->estacionCapturada();
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $estacion));

        $this->get(route('asistencia.captura.index'))
            ->assertOk()
            ->assertSee('Generar oficio')
            ->assertSee('T.M.M./RR.HH./23PMO/00001/2026');
    }

    public function test_la_pantalla_de_captura_avisa_si_falta_capturar(): void
    {
        $estacion = Estacion::factory()->create(['is_operativa' => true]);
        Empleado::factory()->create(['estacion_id' => $estacion->id, 'estatus' => EmpleadoEstatus::Activo->value]);
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $estacion));

        $this->get(route('asistencia.captura.index'))
            ->assertOk()
            ->assertSee('Guarda la asistencia de todo el personal')
            ->assertDontSee('Generar oficio');
    }

    public function test_marca_desactualizado_si_la_asistencia_cambio_despues_de_generar(): void
    {
        $estacion = $this->estacionCapturada();
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $estacion));
        $this->post(route('asistencia.captura.oficio.store'), $this->payload());

        $this->travel(5)->minutes();
        RegistroDiario::query()->update(['estatus' => EstatusAsistencia::Descanso->value, 'updated_at' => now()]);

        $this->get(route('asistencia.captura.index'))
            ->assertOk()
            ->assertSee('La asistencia cambió después de generar este oficio');
    }
}
