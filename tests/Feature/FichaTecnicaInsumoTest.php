<?php

namespace Tests\Feature;

use App\Models\Estacion;
use App\Models\SolicitudInsumo;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Ficha técnica (PDF) opcional por insumo y retiro de la justificación general.
 */
class FichaTecnicaInsumoTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Storage::fake('local');
    }

    private function estacionUser(Estacion $estacion): User
    {
        $user = User::factory()->create(['estacion_id' => $estacion->id]);
        $user->assignRole('Estación');

        return $user;
    }

    /**
     * @param  array<string, mixed>  $fila
     * @return array<string, mixed>
     */
    private function payload(array $fila = []): array
    {
        return [
            'tipo_servicio' => '1er escalón',
            'lugar_trabajo' => 'Bacalar',
            'partidas' => [array_merge(['cantidad' => '2', 'unidad' => 'Pieza', 'descripcion' => 'Foco LED', 'justificacion' => 'Se fundió el anterior'], $fila)],
        ];
    }

    public function test_estacion_attaches_optional_pdf_and_downloads_it(): void
    {
        $estacion = Estacion::factory()->create();
        $solicitud = SolicitudInsumo::factory()->create(['estacion_id' => $estacion->id]);
        $this->actingAsTwoFactorVerified($this->estacionUser($estacion));

        // Sin ficha también se guarda (es opcional).
        $this->put(route('insumos.update', $solicitud), $this->payload())->assertSessionHasNoErrors();
        $this->assertNull($solicitud->partidas()->first()->ficha_tecnica_ruta);

        $pdf = UploadedFile::fake()->create('ficha.pdf', 50, 'application/pdf');
        $this->put(route('insumos.update', $solicitud), $this->payload(['ficha_tecnica' => $pdf]))->assertSessionHasNoErrors();

        $partida = $solicitud->partidas()->first();
        $this->assertSame('ficha.pdf', $partida->ficha_tecnica_nombre);
        Storage::disk('local')->assertExists($partida->ficha_tecnica_ruta);

        $this->get(route('insumos.ficha', [$solicitud, $partida]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_only_pdf_is_accepted(): void
    {
        $estacion = Estacion::factory()->create();
        $solicitud = SolicitudInsumo::factory()->create(['estacion_id' => $estacion->id]);
        $this->actingAsTwoFactorVerified($this->estacionUser($estacion));

        $this->put(route('insumos.update', $solicitud), $this->payload(['ficha_tecnica' => UploadedFile::fake()->image('foto.png')]))
            ->assertInvalid('partidas.0.ficha_tecnica');
    }

    public function test_ficha_is_kept_on_resave_replaced_and_removed(): void
    {
        $estacion = Estacion::factory()->create();
        $solicitud = SolicitudInsumo::factory()->create(['estacion_id' => $estacion->id]);
        $this->actingAsTwoFactorVerified($this->estacionUser($estacion));

        $this->put(route('insumos.update', $solicitud), $this->payload(['ficha_tecnica' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]));
        $primera = $solicitud->partidas()->first();

        // Re-guardar (aun cambiando la descripción) conserva la ficha.
        $this->put(route('insumos.update', $solicitud), $this->payload(['partida_id' => $primera->id, 'descripcion' => 'Foco LED 12W']));
        $conservada = $solicitud->partidas()->first();
        $this->assertSame($primera->ficha_tecnica_ruta, $conservada->ficha_tecnica_ruta);
        Storage::disk('local')->assertExists($primera->ficha_tecnica_ruta);

        // Reemplazar borra el archivo anterior.
        $this->put(route('insumos.update', $solicitud), $this->payload(['partida_id' => $conservada->id, 'ficha_tecnica' => UploadedFile::fake()->create('b.pdf', 10, 'application/pdf')]));
        $nueva = $solicitud->partidas()->first();
        $this->assertSame('b.pdf', $nueva->ficha_tecnica_nombre);
        Storage::disk('local')->assertMissing($primera->ficha_tecnica_ruta);
        Storage::disk('local')->assertExists($nueva->ficha_tecnica_ruta);

        // Quitarla borra el archivo.
        $this->put(route('insumos.update', $solicitud), $this->payload(['partida_id' => $nueva->id, 'quitar_ficha' => '1']));
        $this->assertNull($solicitud->partidas()->first()->ficha_tecnica_ruta);
        Storage::disk('local')->assertMissing($nueva->ficha_tecnica_ruta);
    }

    public function test_ficha_id_of_another_solicitud_is_ignored_and_download_is_scoped(): void
    {
        $suya = Estacion::factory()->create();
        $propia = SolicitudInsumo::factory()->create(['estacion_id' => $suya->id]);
        $ajena = SolicitudInsumo::factory()->create();
        $ajenaPartida = $ajena->partidas()->create(['numero' => 1, 'cantidad' => 1, 'unidad' => 'Pieza', 'descripcion' => 'X', 'justificacion' => 'Y', 'ficha_tecnica_ruta' => 'insumos/fichas/x.pdf', 'ficha_tecnica_nombre' => 'x.pdf']);
        Storage::disk('local')->put('insumos/fichas/x.pdf', '%PDF');
        $this->actingAsTwoFactorVerified($this->estacionUser($suya));

        $this->get(route('insumos.ficha', [$ajena, $ajenaPartida]))->assertForbidden();

        $this->put(route('insumos.update', $propia), $this->payload(['partida_id' => $ajenaPartida->id]))->assertSessionHasNoErrors();
        $this->assertNull($propia->partidas()->first()->ficha_tecnica_ruta);
        Storage::disk('local')->assertExists('insumos/fichas/x.pdf');

        $mia = $propia->partidas()->first();
        $this->get(route('insumos.ficha', [$propia, $mia]))->assertNotFound();
    }

    public function test_form_uses_new_labels_and_no_general_justification(): void
    {
        $estacion = Estacion::factory()->create();
        $solicitud = SolicitudInsumo::factory()->create(['estacion_id' => $estacion->id]);
        $this->actingAsTwoFactorVerified($this->estacionUser($estacion));

        $this->get(route('insumos.show', $solicitud))->assertOk()
            ->assertSee('Justificación específica detallada')
            ->assertSee('Adjuntar ficha técnica')
            ->assertSee('multipart/form-data', false)
            ->assertDontSee('Justificación general');
    }
}
