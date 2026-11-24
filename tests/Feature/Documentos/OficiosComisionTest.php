<?php

namespace Tests\Feature\Documentos;

use App\Enums\EmpleadoEstatus;
use App\Enums\EstatusAsistencia;
use App\Models\DocumentoAsistencia;
use App\Models\Empleado;
use App\Models\Estacion;
use App\Models\OficioComision;
use App\Models\RegistroDiario;
use App\Models\User;
use App\Services\AsistenciaDocumentoService;
use App\Services\Documentos\ConversionPdfException;
use App\Services\Documentos\ConvertidorPdf;
use App\Services\Documentos\RasterizadorPdf;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;
use ZipArchive;

/**
 * Oficios de comisión: alta/baja/descarga con su autorización y que se
 * anexen como hojas al final del Word de la estación y de la zona.
 * LibreOffice y Ghostscript se reemplazan por dobles de prueba.
 */
class OficiosComisionTest extends TestCase
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

        // Cada PDF "tiene" 2 páginas.
        $this->app->instance(RasterizadorPdf::class, new class implements RasterizadorPdf
        {
            public function paginas(string $pdf, string $prefijo, int $maxPaginas): array
            {
                if (str_contains((string) file_get_contents($pdf), 'ROTO')) {
                    throw new ConversionPdfException('No se pudo leer un oficio de comisión.');
                }

                $paginas = [];
                foreach ([1, 2] as $n) {
                    $ruta = sprintf('%s-%03d.jpg', $prefijo, $n);
                    imagejpeg(imagecreatetruecolor(300, 400), $ruta);
                    $paginas[] = $ruta;
                }

                return $paginas;
            }
        });
    }

    private function usuario(string $rol, ?Estacion $estacion = null): User
    {
        $user = User::factory()->create(['estacion_id' => $estacion?->id]);
        $user->assignRole($rol);

        return $user;
    }

    private function estacion(string $nombre, bool $operativa = true): Estacion
    {
        $estacion = Estacion::factory()->create(['nombre' => $nombre, 'is_operativa' => $operativa]);
        $empleado = Empleado::factory()->create(['estacion_id' => $estacion->id, 'estatus' => EmpleadoEstatus::Activo->value, 'nombre_completo' => 'Persona '.$nombre]);
        RegistroDiario::factory()->create([
            'empleado_id' => $empleado->id,
            'estacion_id' => $estacion->id,
            'fecha' => CarbonImmutable::today()->toDateString(),
            'estatus' => EstatusAsistencia::Presente->value,
        ]);

        return $estacion;
    }

    private function subir(Estacion $estacion, array $archivos, array $extra = [])
    {
        return $this->post(route('asistencia.captura.oficios-comision.store'), $extra + ['archivos' => $archivos, 'estacion' => $estacion->id]);
    }

    /** @return array{imagenes: int, texto: string} */
    private function contenidoDelDocx(DocumentoAsistencia $documento): array
    {
        $ruta = tempnam(sys_get_temp_dir(), 'ofc').'.docx';
        file_put_contents($ruta, Storage::disk('local')->get($documento->docx_path));
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($ruta) === true);
        $imagenes = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $imagenes += str_starts_with((string) $zip->getNameIndex($i), 'word/media/') ? 1 : 0;
        }
        $texto = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($ruta);

        return ['imagenes' => $imagenes, 'texto' => $texto];
    }

    // --- Alta / baja / descarga ----------------------------------------------

    public function test_estacion_sube_sus_oficios_y_quedan_guardados_con_nombre_del_servidor(): void
    {
        $estacion = $this->estacion('Puerto Morelos');
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $estacion));

        $this->subir($estacion, [
            UploadedFile::fake()->create('Oficio ../comision.pdf', 50, 'application/pdf'),
            UploadedFile::fake()->image('foto.jpg', 400, 600),
        ], ['vigente_hasta' => '2026-12-31'])->assertRedirect(route('asistencia.captura.index'))->assertSessionHas('paso', 'oficio');

        $this->assertSame(2, OficioComision::count());
        $oficio = OficioComision::where('extension', 'pdf')->firstOrFail();
        $this->assertSame($estacion->id, $oficio->estacion_id);
        $this->assertSame('2026-12-31', $oficio->vigente_hasta->toDateString());
        $this->assertStringNotContainsString('Oficio', basename($oficio->ruta));
        Storage::disk('local')->assertExists($oficio->ruta);
        $this->assertSame(1, Activity::where('log_name', 'oficio_comision')->where('description', 'subidos')->count());
    }

    public function test_estacion_no_puede_subir_a_otra_estacion_ni_formatos_no_admitidos(): void
    {
        $propia = $this->estacion('Puerto Morelos');
        $ajena = $this->estacion('Tulum');
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $propia));

        // Aunque mande el id de otra estación, el servidor usa la suya.
        $this->subir($ajena, [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])->assertRedirect();
        $this->assertSame($propia->id, OficioComision::firstOrFail()->estacion_id);

        $this->subir($propia, [UploadedFile::fake()->create('virus.exe', 10, 'application/octet-stream')])->assertInvalid('archivos.0');
        $this->subir($propia, [UploadedFile::fake()->create('grande.pdf', 11000, 'application/pdf')])->assertInvalid('archivos.0');
        $this->assertSame(1, OficioComision::count());
    }

    public function test_solo_su_estacion_o_la_zona_descarga_y_quita_un_oficio(): void
    {
        $propia = $this->estacion('Puerto Morelos');
        $ajena = $this->estacion('Tulum');
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $propia));
        $this->subir($propia, [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]);
        $oficio = OficioComision::firstOrFail();

        $this->get(route('asistencia.oficios-comision.download', $oficio))->assertOk();

        $this->actingAsTwoFactorVerified($this->usuario('Estación', $ajena));
        $this->get(route('asistencia.oficios-comision.download', $oficio))->assertNotFound();
        $this->delete(route('asistencia.oficios-comision.destroy', $oficio))->assertNotFound();
        $this->assertNull($oficio->fresh()->deleted_at);

        $this->actingAsTwoFactorVerified($this->usuario('Jefe de Zona'));
        $this->delete(route('asistencia.oficios-comision.destroy', $oficio))->assertRedirect();
        $this->assertSoftDeleted($oficio);
        Storage::disk('local')->assertMissing($oficio->ruta);
        $this->assertSame(1, Activity::where('log_name', 'oficio_comision')->where('description', 'eliminado')->count());
    }

    public function test_invitado_no_puede_subir(): void
    {
        $this->post(route('asistencia.captura.oficios-comision.store'), [])->assertRedirect(route('login'));
    }

    // --- Anexos en el control -----------------------------------------------

    public function test_el_word_de_la_estacion_lleva_los_oficios_vigentes_como_hojas_al_final(): void
    {
        $estacion = $this->estacion('Puerto Morelos');
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $estacion));

        // PDF (2 hojas) + imagen (1 hoja) vigentes; otro PDF vencido no entra.
        $this->subir($estacion, [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'), UploadedFile::fake()->image('b.png', 300, 500)]);
        $this->subir($estacion, [UploadedFile::fake()->create('vencido.pdf', 10, 'application/pdf')], ['vigente_hasta' => CarbonImmutable::yesterday()->toDateString()]);

        $this->post(route('asistencia.captura.oficio.store'), [
            'fecha' => CarbonImmutable::today()->toDateString(),
            'numero_oficio' => 'T.M.M./RR.HH./23PMO/00001/2026',
            'firmante_cargo' => 'Gerente de Estación',
            'firmante_nombre' => 'Lic. Firmante Dummy',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $docx = $this->contenidoDelDocx(DocumentoAsistencia::firstOrFail());

        $this->assertSame(2 + 3, $docx['imagenes'], 'membrete + pie + 3 hojas de oficios');
    }

    public function test_el_control_de_la_zona_trae_los_oficios_de_todas_las_estaciones(): void
    {
        $uno = $this->estacion('Bacalar');
        $dos = $this->estacion('Edificio Zonal Este', false);
        $this->actingAsTwoFactorVerified($this->usuario('Jefe de Zona'));
        $this->subir($uno, [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]);
        $this->subir($dos, [UploadedFile::fake()->image('b.jpg', 300, 500)]);

        $this->post(route('asistencia.zona.oficio.store'), [
            'fecha' => CarbonImmutable::today()->toDateString(),
            'numero_oficio' => 'TM/UAI/CGGIF/DGTZO/1735',
            'firmante' => 'director',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $docx = $this->contenidoDelDocx(DocumentoAsistencia::firstOrFail());

        $this->assertSame(2 + 3, $docx['imagenes'], 'membrete + pie + 2 hojas de Bacalar + 1 del Edificio');
    }

    public function test_sin_oficios_el_control_sale_igual_que_antes(): void
    {
        $estacion = $this->estacion('Puerto Morelos');
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $estacion));

        $this->post(route('asistencia.captura.oficio.store'), [
            'fecha' => CarbonImmutable::today()->toDateString(),
            'numero_oficio' => 'T.M.M./RR.HH./23PMO/00001/2026',
            'firmante_cargo' => 'Gerente',
            'firmante_nombre' => 'Lic. Firmante Dummy',
        ])->assertRedirect();

        $this->assertSame(2, $this->contenidoDelDocx(DocumentoAsistencia::firstOrFail())['imagenes']);
    }

    public function test_un_oficio_ilegible_detiene_la_generacion_y_dice_cual_es(): void
    {
        $estacion = $this->estacion('Puerto Morelos');
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $estacion));
        $this->subir($estacion, [UploadedFile::fake()->createWithContent('roto.pdf', 'ROTO')]);

        $this->post(route('asistencia.captura.oficio.store'), [
            'fecha' => CarbonImmutable::today()->toDateString(),
            'numero_oficio' => 'T.M.M./RR.HH./23PMO/00001/2026',
            'firmante_cargo' => 'Gerente',
            'firmante_nombre' => 'Lic. Firmante Dummy',
        ])->assertSessionHasErrors(['oficio']);

        $this->assertSame(0, DocumentoAsistencia::count());
    }

    public function test_subir_o_quitar_un_oficio_marca_desactualizado_el_control_generado(): void
    {
        $estacion = $this->estacion('Puerto Morelos');
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $estacion));
        $this->post(route('asistencia.captura.oficio.store'), [
            'fecha' => CarbonImmutable::today()->toDateString(),
            'numero_oficio' => 'T.M.M./RR.HH./23PMO/00001/2026',
            'firmante_cargo' => 'Gerente',
            'firmante_nombre' => 'Lic. Firmante Dummy',
        ]);
        $documento = DocumentoAsistencia::firstOrFail();
        $servicio = app(AsistenciaDocumentoService::class);
        $this->assertFalse($servicio->estaDesactualizado($documento));

        $this->travel(5)->seconds();
        $this->subir($estacion, [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]);

        $this->assertTrue($servicio->estaDesactualizado($documento->fresh()));
    }

    public function test_la_pantalla_de_captura_lista_los_oficios_de_la_estacion(): void
    {
        $estacion = $this->estacion('Puerto Morelos');
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $estacion));
        $this->subir($estacion, [UploadedFile::fake()->create('mi-oficio.pdf', 10, 'application/pdf')]);

        $this->get(route('asistencia.captura.index'))->assertOk()->assertSee('mi-oficio.pdf')->assertSee('Subir oficios');
    }
}
