<?php

namespace Tests\Feature;

use App\Jobs\EnviarFotoAlPortal;
use App\Models\User;
use App\Services\AvatarService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Foto de perfil única: subida local (se reprocesa y se avisa al portal) y puente del portal (sin eco).
 */
class FotoPerfilTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private const SECRETO = 'secreto-portal-a-dashboard-de-prueba-0123456789';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(RoleSeeder::class);
        config([
            'ecosistema.puente.portal.secreto' => self::SECRETO,
            'ecosistema.puente.ips' => [],
        ]);
    }

    private function png(int $lado = 300): string
    {
        $imagen = imagecreatetruecolor($lado, $lado);
        ob_start();
        imagepng($imagen);

        return (string) ob_get_clean();
    }

    /** @return array<string, string> */
    private function firmado(string $metodo, string $cuerpo): array
    {
        $fecha = (string) now()->getTimestamp();
        $nonce = Str::random(40);
        $canonico = implode("\n", [$metodo, '/interno/portal/avatar', '', 'portal', $fecha, $nonce, hash('sha256', $cuerpo)]);

        return [
            'X-Puente-Sistema' => 'portal', 'X-Puente-Fecha' => $fecha, 'X-Puente-Nonce' => $nonce,
            'X-Puente-Firma' => hash_hmac('sha256', $canonico, self::SECRETO),
            'CONTENT_TYPE' => 'application/json', 'Accept' => 'application/json',
        ];
    }

    private function puente(string $metodo, array $datos): TestResponse
    {
        $cuerpo = (string) json_encode($datos);

        return $this->call($metodo, '/interno/portal/avatar', [], [], [], $this->transformHeadersToServerVars($this->firmado($metodo, $cuerpo)), $cuerpo);
    }

    public function test_la_foto_subida_se_reprocesa_a_jpeg_cuadrado_y_se_avisa_al_portal(): void
    {
        Bus::fake([EnviarFotoAlPortal::class]);
        $user = User::factory()->create();
        $this->actingAsTwoFactorVerified($user);

        $this->post(route('profile.foto.update'), ['foto' => UploadedFile::fake()->createWithContent('f.png', $this->png())])
            ->assertRedirect(route('profile.edit'));

        $user->refresh();
        $info = getimagesizefromstring((string) Storage::disk('local')->get($user->avatar_path));
        $this->assertSame([256, 256, IMAGETYPE_JPEG], [$info[0], $info[1], $info[2]]);
        Bus::assertDispatched(EnviarFotoAlPortal::class);
        $this->get($user->urlFoto())->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_un_archivo_que_no_es_imagen_se_rechaza(): void
    {
        $user = User::factory()->create();
        $this->actingAsTwoFactorVerified($user);

        $this->post(route('profile.foto.update'), ['foto' => UploadedFile::fake()->createWithContent('f.png', 'no soy imagen')])
            ->assertInvalid('foto');
        $this->assertNull($user->refresh()->avatar_path);
    }

    public function test_quitar_la_foto_borra_el_archivo_y_avisa_al_portal(): void
    {
        $user = User::factory()->create();
        app(AvatarService::class)->guardarBytes($user, $this->png(), propagar: false);
        $ruta = $user->avatar_path;
        Bus::fake([EnviarFotoAlPortal::class]);
        $this->actingAsTwoFactorVerified($user);

        $this->delete(route('profile.foto.destroy'))->assertRedirect();

        $this->assertNull($user->refresh()->avatar_path);
        Storage::disk('local')->assertMissing($ruta);
        Bus::assertDispatched(EnviarFotoAlPortal::class);
    }

    public function test_el_portal_pone_y_quita_la_foto_sin_eco(): void
    {
        Bus::fake([EnviarFotoAlPortal::class]);
        $user = User::factory()->create(['email' => 'persona@example.com']);

        $this->puente('PUT', ['email' => 'PERSONA@example.com', 'imagen' => base64_encode($this->png())])->assertOk();
        $this->assertNotNull($user->refresh()->avatar_path);

        $leida = $this->puente('GET', ['email' => 'persona@example.com']);
        $this->assertNotNull($leida->json('imagen'));

        $this->puente('DELETE', ['email' => 'persona@example.com'])->assertOk();
        $this->assertNull($user->refresh()->avatar_path);
        Bus::assertNotDispatched(EnviarFotoAlPortal::class);
    }

    public function test_el_puente_rechaza_firma_invalida_cuenta_ajena_e_imagen_falsa(): void
    {
        User::factory()->create(['email' => 'persona@example.com']);
        $cuerpo = (string) json_encode(['email' => 'persona@example.com']);
        $cabeceras = $this->firmado('DELETE', $cuerpo);
        $cabeceras['X-Puente-Firma'] = str_repeat('0', 64);
        $this->call('DELETE', '/interno/portal/avatar', [], [], [], $this->transformHeadersToServerVars($cabeceras), $cuerpo)->assertUnauthorized();

        $this->puente('DELETE', ['email' => 'nadie@example.com'])->assertNotFound();
        $this->puente('PUT', ['email' => 'persona@example.com', 'imagen' => base64_encode('texto')])->assertStatus(422);
    }

    public function test_el_envio_al_portal_va_firmado_y_manda_la_foto_vigente(): void
    {
        config(['ecosistema.puente.portal.url' => 'https://portal.test', 'ecosistema.sistema' => 'dashboard']);
        $user = User::factory()->create(['email' => 'persona@example.com']);
        app(AvatarService::class)->guardarBytes($user, $this->png(), propagar: false);
        Http::fake(['portal.test/*' => Http::response(['estado' => 'ok'])]);

        EnviarFotoAlPortal::dispatchSync($user->id);

        Http::assertSent(fn ($r) => $r->method() === 'PUT'
            && $r->url() === 'https://portal.test/interno/subsistemas/avatar'
            && $r->hasHeader('X-Puente-Firma')
            && $r->header('X-Puente-Sistema') === ['dashboard']
            && ($r->data()['email'] ?? null) === 'persona@example.com'
            && ! empty($r->data()['imagen']));
    }
}
