<?php

namespace Tests\Feature\Documentos;

use App\Enums\EmpleadoEstatus;
use App\Enums\EstatusAsistencia;
use App\Enums\TipoPlaza;
use App\Models\ComisionadoFuera;
use App\Models\ComisionadoVisitante;
use App\Models\DocumentoAsistencia;
use App\Models\Empleado;
use App\Models\Estacion;
use App\Models\RegistroDiario;
use App\Models\User;
use App\Services\AsistenciaCapturaService;
use App\Services\Documentos\AsistenciaDocumentoDataBuilder;
use App\Services\Documentos\ConvertidorPdf;
use App\Services\Documentos\DirectorioTemporal;
use App\Services\Documentos\NumeroOficioService;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Regression coverage for the findings of the security audit and code review
 * of the oficio generation.
 */
class OficioEndurecimientoTest extends TestCase
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

    private function estacionCapturada(string $nombre = 'Puerto Morelos'): Estacion
    {
        $estacion = Estacion::factory()->create(['nombre' => $nombre, 'is_operativa' => true]);
        $empleado = Empleado::factory()->create(['estacion_id' => $estacion->id, 'estatus' => EmpleadoEstatus::Activo->value]);
        RegistroDiario::factory()->create([
            'empleado_id' => $empleado->id,
            'estacion_id' => $estacion->id,
            'fecha' => CarbonImmutable::today()->toDateString(),
            'estatus' => EstatusAsistencia::Presente->value,
        ]);

        return $estacion;
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'fecha' => CarbonImmutable::today()->toDateString(),
            'numero_oficio' => 'T.M.M./RR.HH./23PMO/00001/2026',
            'firmante_cargo' => 'Gerente de Estación',
            'firmante_nombre' => 'Lic. Firmante Dummy',
        ], $override);
    }

    public function test_regenerar_con_los_mismos_datos_deja_entrada_en_la_bitacora(): void
    {
        $estacion = $this->estacionCapturada();
        $usuario = $this->actingAsTwoFactorVerified($this->usuario('Estación', $estacion));

        $this->post(route('asistencia.captura.oficio.store'), $this->payload());
        $this->post(route('asistencia.captura.oficio.store'), $this->payload());

        $regeneraciones = Activity::query()
            ->where('log_name', 'documento_asistencia')
            ->where('description', 'regenerado')
            ->where('causer_id', $usuario->id)
            ->get();
        $this->assertCount(1, $regeneraciones);
        $this->assertSame('T.M.M./RR.HH./23PMO/00001/2026', $regeneraciones->first()->properties['numero_oficio']);
    }

    public function test_las_rutas_de_oficios_exigen_2fa_completado(): void
    {
        $estacion = $this->estacionCapturada();
        $usuario = $this->usuario('Estación', $estacion);
        $documento = DocumentoAsistencia::factory()->create(['estacion_id' => $estacion->id]);
        $this->actingAs($usuario); // logged in, but the OTP challenge is NOT completed

        $this->post(route('asistencia.captura.oficio.store'), $this->payload())->assertRedirect();
        $this->get(route('asistencia.documentos.download', [$documento, 'pdf']))->assertRedirect();
        $this->assertSame(1, DocumentoAsistencia::count());
    }

    public function test_una_cuenta_de_estacion_no_puede_descargar_el_oficio_de_zona(): void
    {
        $estacion = Estacion::factory()->create();
        $zona = DocumentoAsistencia::factory()->zona()->create();
        Storage::disk('local')->put($zona->pdf_path, '%PDF');
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $estacion));

        $this->get(route('asistencia.documentos.download', [$zona, 'pdf']))->assertNotFound();
    }

    public function test_un_usuario_sin_rol_no_puede_descargar(): void
    {
        $documento = DocumentoAsistencia::factory()->create();
        Storage::disk('local')->put($documento->pdf_path, '%PDF');
        $this->actingAsTwoFactorVerified(User::factory()->create());

        $this->get(route('asistencia.documentos.download', [$documento, 'pdf']))->assertNotFound();
    }

    public function test_firmante_con_marcador_de_plantilla_o_caracteres_de_control_es_rechazado(): void
    {
        $estacion = $this->estacionCapturada();
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $estacion));

        $this->post(route('asistencia.captura.oficio.store'), $this->payload([
            'firmante_nombre' => 'Nombre ${c_no}',
            'firmante_cargo' => "Cargo\x07",
        ]))->assertSessionHasErrors(['firmante_nombre', 'firmante_cargo']);
    }

    public function test_firmante_con_ampersand_y_menor_que_se_acepta_y_no_rompe_el_docx(): void
    {
        $estacion = $this->estacionCapturada();
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $estacion));

        $this->post(route('asistencia.captura.oficio.store'), $this->payload(['firmante_nombre' => 'Ing. A & B <C>']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Ing. A & B <C>', DocumentoAsistencia::firstOrFail()->firmante_nombre);
    }

    public function test_fecha_con_hora_o_texto_libre_es_rechazada(): void
    {
        $estacion = $this->estacionCapturada();
        $this->actingAsTwoFactorVerified($this->usuario('Administrador'));

        $this->post(route('asistencia.captura.oficio.store'), $this->payload(['estacion' => $estacion->id, 'fecha' => 'tomorrow']))
            ->assertSessionHasErrors('fecha');
        $this->assertSame(0, DocumentoAsistencia::count());
    }

    public function test_un_folio_ya_usado_por_carrera_se_reporta_como_error_de_folio(): void
    {
        $estacion = $this->estacionCapturada();
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $estacion));
        // The unique rule passes (no clash when validating); another request then takes the
        // folio before this one saves, so the DB's unique index rejects the insert.
        DocumentoAsistencia::creating(function () {
            throw new UniqueConstraintViolationException('sqlite', 'insert into documentos_asistencia', [], new \Exception('UNIQUE'));
        });

        $this->post(route('asistencia.captura.oficio.store'), $this->payload())
            ->assertSessionHasErrors('numero_oficio')
            ->assertSessionDoesntHaveErrors('oficio');
    }

    public function test_una_persona_con_dos_comisiones_el_mismo_dia_cuenta_y_se_imprime_una_vez(): void
    {
        $fecha = CarbonImmutable::today();
        $pmo = Estacion::factory()->create(['is_operativa' => true]);
        $persona = Empleado::factory()->create([
            'estacion_id' => $pmo->id, 'tipo_plaza' => TipoPlaza::Permanente->value, 'nombre_completo' => 'Doble Comision',
        ]);
        foreach (['CGOFP', 'CGGIF'] as $destino) {
            ComisionadoFuera::factory()->create([
                'empleado_id' => $persona->id, 'estacion_id' => $pmo->id, 'fecha' => $fecha->toDateString(),
                'coordinacion_destino' => $destino, 'ubicacion_destino' => 'Mérida', 'fecha_inicio' => null, 'fecha_fin' => null,
            ]);
        }

        $data = (new AsistenciaDocumentoDataBuilder(app(AsistenciaCapturaService::class)))->paraZona($fecha);

        $this->assertCount(1, $data->comisionadosAOtrasAreas);
        $this->assertSame(1, $data->resumenComisionados->comisionPermanente);
        $this->assertSame(1, $data->resumenComisionados->total);
    }

    public function test_dos_estaciones_sin_clave_no_reciben_el_mismo_folio_sugerido(): void
    {
        $bacalar = Estacion::factory()->create(['nombre' => 'Bacalar']);
        $chetumal = Estacion::factory()->create(['nombre' => 'Chetumal']);
        $fecha = CarbonImmutable::parse('2026-09-18');
        DocumentoAsistencia::factory()->create([
            'estacion_id' => $bacalar->id, 'fecha' => $fecha->toDateString(), 'numero_oficio' => 'T.M.M./RR.HH./00001/2026',
        ]);

        $sugerido = app(NumeroOficioService::class)->sugerirParaEstacion($chetumal, $fecha);

        $this->assertSame('T.M.M./RR.HH./00002/2026', $sugerido);
    }

    public function test_el_directorio_temporal_es_privado_y_purga_lo_obsoleto(): void
    {
        $ruta = DirectorioTemporal::ruta();
        $viejo = $ruta.DIRECTORY_SEPARATOR.'oficio_viejo_prueba.docx';
        file_put_contents($viejo, 'x');
        touch($viejo, time() - 7200);
        $reciente = $ruta.DIRECTORY_SEPARATOR.'oficio_reciente_prueba.docx';
        file_put_contents($reciente, 'x');

        DirectorioTemporal::ruta();

        $this->assertFileDoesNotExist($viejo);
        $this->assertFileExists($reciente);
        unlink($reciente);
        $this->assertStringContainsString('storage/app/private', str_replace(DIRECTORY_SEPARATOR, '/', $ruta));
    }

    public function test_desactualizado_solo_mira_registros_diarios_en_el_oficio_de_estacion(): void
    {
        $estacion = $this->estacionCapturada();
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $estacion));
        $this->post(route('asistencia.captura.oficio.store'), $this->payload());

        $this->travel(5)->minutes();
        ComisionadoVisitante::factory()->create(['estacion_id' => $estacion->id, 'fecha' => now()->toDateString()]);

        $this->get(route('asistencia.captura.index'))
            ->assertOk()
            ->assertDontSee('La asistencia cambió después de generar este oficio');
    }
}
