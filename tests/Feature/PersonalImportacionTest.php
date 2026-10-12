<?php

namespace Tests\Feature;

use App\Models\Empleado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithSecciones;
use Tests\TestCase;

/**
 * RR.HH. > Personal: importar el Excel arrastrándolo, con vista previa que
 * no deja cambios. Excel SINTÉTICO (nunca el archivo real con datos
 * personales).
 */
class PersonalImportacionTest extends TestCase
{
    use InteractsWithSecciones, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function excelPersonal(): UploadedFile
    {
        $fila = fn (array $columnas) => array_replace(array_fill(0, 30, null), $columnas);

        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet()->setTitle('Base de Datos Zona Oriente');
        $hoja->fromArray($fila([3 => 'No. Empleado', 5 => 'Nombre']), null, 'A1');
        $hoja->fromArray($fila([3 => '90001', 5 => 'Persona Uno', 6 => 'Auxiliar']), null, 'A2');
        $hoja->fromArray($fila([3 => '90002', 5 => 'Persona Dos', 6 => 'Taquillero']), null, 'A3');

        $ruta = tempnam(sys_get_temp_dir(), 'pers');
        (new Xlsx($libro))->save($ruta);

        return new UploadedFile($ruta, 'base.xlsx', null, null, true);
    }

    public function test_preview_counts_changes_without_saving_and_apply_imports(): void
    {
        $usuario = $this->comoAdministrador();

        $this->postJson(route('agenda.personal.importar.store'), ['archivos' => [$this->excelPersonal()]])
            ->assertCreated()
            ->assertJsonPath('data.redirigir', route('agenda.personal.importar.show'));

        $vista = $this->get(route('agenda.personal.importar.show'));
        $vista->assertOk();
        $this->assertSame(2, $vista->viewData('resumen')['creados']);
        $this->assertSame(0, Empleado::query()->count());

        $this->post(route('agenda.personal.importar.aplicar'))->assertRedirect(route('agenda.personal.index'));

        $this->assertSame(2, Empleado::query()->count());
        $this->assertTrue(Activity::query()->where('event', 'personal_importado')->where('causer_id', $usuario->id)->exists());
        $this->assertSame([], Storage::disk('local')->allFiles('imports/personal-pendiente'));
    }

    public function test_cancelling_discards_the_pending_file(): void
    {
        $this->comoAdministrador();
        $this->post(route('agenda.personal.importar.store'), ['archivo' => $this->excelPersonal()]);

        $this->delete(route('agenda.personal.importar.destroy'))->assertRedirect(route('agenda.personal.index'));

        $this->assertSame([], Storage::disk('local')->allFiles('imports/personal-pendiente'));
        $this->get(route('agenda.personal.importar.show'))->assertRedirect(route('agenda.personal.index'));
    }

    public function test_only_excel_files_are_accepted(): void
    {
        $this->comoAdministrador();

        $this->postJson(route('agenda.personal.importar.store'), ['archivo' => UploadedFile::fake()->create('base.pdf', 5, 'application/pdf')])
            ->assertJsonValidationErrors('archivo');
    }

    public function test_zone_chief_without_admin_role_cannot_import(): void
    {
        $this->actingAsTwoFactorVerified($this->usuarioConRol('Jefe de Zona'));

        $this->post(route('agenda.personal.importar.store'), ['archivo' => $this->excelPersonal()])->assertForbidden();
    }
}
