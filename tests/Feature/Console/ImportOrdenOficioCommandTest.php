<?php

namespace Tests\Feature\Console;

use App\Models\Empleado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportOrdenOficioCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_asigna_orden_oficio_por_numero_de_empleado_y_reporta_solo_conteos(): void
    {
        $uno = Empleado::factory()->create(['no_empleado' => '240001']);
        $dos = Empleado::factory()->create(['no_empleado' => '240002']);
        Storage::disk('local')->put('imports/orden.json', json_encode(['240001' => 5, '240002' => 6, '999999' => 7]));

        $this->artisan('asistencia:importar-orden-oficio', ['archivo' => 'imports/orden.json'])
            ->expectsOutput('Empleados actualizados: 2.')
            ->expectsOutput('Números del archivo sin empleado en la base: 1.')
            ->assertSuccessful();

        $this->assertSame(5, $uno->fresh()->orden_oficio);
        $this->assertSame(6, $dos->fresh()->orden_oficio);
    }

    public function test_falla_con_mensaje_claro_si_el_archivo_no_existe(): void
    {
        $this->artisan('asistencia:importar-orden-oficio', ['archivo' => 'imports/no-existe.json'])
            ->assertFailed();
    }

    public function test_falla_si_el_archivo_no_es_un_objeto_json_valido(): void
    {
        Storage::disk('local')->put('imports/malo.json', 'no es json');

        $this->artisan('asistencia:importar-orden-oficio', ['archivo' => 'imports/malo.json'])
            ->assertFailed();
    }

    public function test_ignora_posiciones_invalidas(): void
    {
        $empleado = Empleado::factory()->create(['no_empleado' => '240001', 'orden_oficio' => null]);
        Storage::disk('local')->put('imports/orden.json', json_encode(['240001' => 'x']));

        $this->artisan('asistencia:importar-orden-oficio', ['archivo' => 'imports/orden.json'])->assertSuccessful();

        $this->assertNull($empleado->fresh()->orden_oficio);
    }
}
