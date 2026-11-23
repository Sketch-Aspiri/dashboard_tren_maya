<?php

namespace Tests\Feature;

use App\Services\Asistencia\GoogleSheetsCliente;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class GoogleSheetsClienteTest extends TestCase
{
    private string $ruta = '';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $llave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        if ($llave === false || ! openssl_pkey_export($llave, $privada)) {
            $this->markTestSkipped('OpenSSL de este PHP no puede generar llaves (falta openssl.cnf).');
        }

        $this->ruta = tempnam(sys_get_temp_dir(), 'gs');
        file_put_contents($this->ruta, json_encode(['client_email' => 'robot@proyecto.iam.gserviceaccount.com', 'private_key' => $privada]));
    }

    protected function tearDown(): void
    {
        if ($this->ruta !== '') {
            @unlink($this->ruta);
        }

        parent::tearDown();
    }

    public function test_pide_token_con_jwt_firmado_y_lee_con_valores_sin_formato(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok-prueba']),
            'sheets.googleapis.com/*' => Http::response(['values' => [['No.', 'Nombre'], ['101', 'Ana']]]),
        ]);

        $valores = (new GoogleSheetsCliente($this->ruta))->leer('id-hoja', "'Noviembre'!A1:AO300");

        $this->assertSame([['No.', 'Nombre'], ['101', 'Ana']], $valores);
        Http::assertSent(function (Request $r) {
            if (str_contains($r->url(), 'oauth2')) {
                $partes = explode('.', (string) $r['assertion']);

                return $r['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer' && count($partes) === 3;
            }

            return $r->hasHeader('Authorization', 'Bearer tok-prueba')
                && str_contains($r->url(), 'valueRenderOption=UNFORMATTED_VALUE')
                && str_contains($r->url(), 'dateTimeRenderOption=SERIAL_NUMBER');
        });
    }

    public function test_escribe_con_un_solo_batch_update_raw(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok']),
            'sheets.googleapis.com/*' => Http::response([]),
        ]);
        $celdas = [['range' => "'Noviembre'!F2", 'values' => [['PRESENTE']]]];

        (new GoogleSheetsCliente($this->ruta))->escribir('id-hoja', $celdas);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/values:batchUpdate')
            && $r['valueInputOption'] === 'RAW' && $r['data'] === $celdas);
    }

    public function test_un_error_de_google_no_filtra_el_token(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok-secreto']),
            'sheets.googleapis.com/*' => Http::response(['error' => ['message' => 'The caller does not have permission']], 403),
        ]);

        try {
            (new GoogleSheetsCliente($this->ruta))->leer('id', 'A1');
            $this->fail('Debió lanzar RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('HTTP 403', $e->getMessage());
            $this->assertStringNotContainsString('tok-secreto', $e->getMessage());
        }
    }

    public function test_sin_archivo_de_credenciales_falla_con_mensaje_claro(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('credenciales');

        (new GoogleSheetsCliente('/no/existe.json'))->leer('id', 'A1');
    }
}
