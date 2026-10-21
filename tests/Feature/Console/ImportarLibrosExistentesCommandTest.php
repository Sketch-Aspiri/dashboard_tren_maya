<?php

namespace Tests\Feature\Console;

use App\Models\Seccion;
use App\Models\SeccionArchivo;
use App\Models\SeccionFila;
use App\Models\SeccionImportacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ImportarLibrosExistentesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function archivoExistente(Seccion $seccion): SeccionArchivo
    {
        $libro = new Spreadsheet;
        $libro->removeSheetByIndex(0);

        $hojas = [
            'Escaleras' => [['Equipo', 'Estado'], ['E-1', 'Operando'], ['E-2', 'Falla']],
            'Elevadores' => [['Equipo', 'Piso'], ['V-1', 3]],
            'Andenes' => [['Andén', 'Longitud'], ['A', 120], ['B', 110]],
            'Vacía' => [['Solo', 'títulos'], [null, null]],
        ];

        foreach ($hojas as $nombre => $filas) {
            $libro->createSheet()->setTitle($nombre)->fromArray($filas, null, 'A1', true);
        }

        $ruta = "secciones/{$seccion->id}/existente.xlsx";
        $temporal = tempnam(sys_get_temp_dir(), 'xlsx');
        (new Xlsx($libro))->save($temporal);
        Storage::disk('local')->put($ruta, (string) file_get_contents($temporal));

        $archivo = new SeccionArchivo([
            'nombre_original' => 'existente.xlsx', 'ruta' => $ruta, 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'extension' => 'xlsx', 'tamano' => Storage::disk('local')->size($ruta),
        ]);
        $archivo->forceFill(['seccion_id' => $seccion->id])->save();

        return $archivo;
    }

    public function test_existing_workbook_generates_one_table_per_sheet_with_data(): void
    {
        $seccion = Seccion::factory()->create();
        $this->archivoExistente($seccion);

        $this->artisan('secciones:importar-libros-existentes', ['--force' => true])->assertSuccessful();

        foreach (['Escaleras' => 2, 'Elevadores' => 1, 'Andenes' => 2] as $nombre => $filas) {
            $this->assertSame($filas, Seccion::query()->where('nombre', $nombre)->sole()->filas()->count());
        }
        $this->assertNull(Seccion::query()->where('nombre', 'Vacía')->first());
        $this->assertSame(1, Activity::query()->where('event', 'importacion_libros_existentes')->count());
    }

    public function test_second_run_does_not_duplicate_rows(): void
    {
        $seccion = Seccion::factory()->create();
        $this->archivoExistente($seccion);

        $this->artisan('secciones:importar-libros-existentes', ['--force' => true])->assertSuccessful();
        $this->artisan('secciones:importar-libros-existentes', ['--force' => true])->assertSuccessful();

        $this->assertSame(5, SeccionFila::query()->count());
        $this->assertSame(4, Seccion::query()->count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $seccion = Seccion::factory()->create();
        $this->archivoExistente($seccion);

        $this->artisan('secciones:importar-libros-existentes', ['--dry-run' => true])
            ->expectsOutputToContain('Escaleras')
            ->assertSuccessful();

        $this->assertSame(0, SeccionFila::query()->count());
        $this->assertSame(1, Seccion::query()->count());
        $this->assertDatabaseCount('seccion_importaciones', 0);
        $this->assertSame(0, Activity::query()->where('event', 'importacion_libros_existentes')->count());
    }

    public function test_seccion_option_limits_the_run(): void
    {
        $uno = Seccion::factory()->create();
        $otra = Seccion::factory()->create();
        $this->archivoExistente($uno);
        $this->archivoExistente($otra);

        $this->artisan('secciones:importar-libros-existentes', ['--seccion' => $uno->slug, '--force' => true])->assertSuccessful();

        $this->assertSame(1, SeccionImportacion::query()->count());
        $this->assertSame(5, SeccionFila::query()->count());
    }

    public function test_interactive_run_asks_for_confirmation_and_writes_nothing_when_declined(): void
    {
        $seccion = Seccion::factory()->create();
        $this->archivoExistente($seccion);

        $this->artisan('secciones:importar-libros-existentes')
            ->expectsConfirmation('Esto creará tablas y filas en las secciones a partir de los Excel guardados (se puede deshacer desde Cargas). ¿Continuar?', 'no')
            ->assertSuccessful();

        $this->assertSame(0, SeccionFila::query()->count());
        $this->assertSame(0, Activity::query()->where('event', 'importacion_libros_existentes')->count());
    }

    public function test_no_interaction_skips_the_confirmation_and_the_event_is_marked_as_cli(): void
    {
        $seccion = Seccion::factory()->create();
        $this->archivoExistente($seccion);

        $this->artisan('secciones:importar-libros-existentes', ['--no-interaction' => true])->assertSuccessful();

        $this->assertSame(5, SeccionFila::query()->count());
        $this->assertSame('cli', Activity::query()->where('event', 'importacion_libros_existentes')->sole()->getExtraProperty('origen'));
    }
}
