<?php

namespace App\Services\Asistencia;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google Sheets API v4 con una cuenta de servicio (JWT RS256 firmado con openssl; no hace falta google/apiclient).
 * La hoja debe estar compartida con el correo de la cuenta de servicio como editora. Los errores nunca incluyen el
 * token ni la llave: solo el código HTTP y el mensaje que devuelve Google.
 */
final class GoogleSheetsCliente implements HojaGoogleCliente
{
    private const ALCANCE = 'https://www.googleapis.com/auth/spreadsheets';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public function __construct(private readonly ?string $rutaCredenciales) {}

    public function leer(string $spreadsheetId, string $rango): array
    {
        $respuesta = Http::withToken($this->token())->acceptJson()->timeout(30)
            ->get('https://sheets.googleapis.com/v4/spreadsheets/'.rawurlencode($spreadsheetId).'/values/'.rawurlencode($rango), [
                'valueRenderOption' => 'UNFORMATTED_VALUE',
                'dateTimeRenderOption' => 'SERIAL_NUMBER',
            ]);

        $this->exigirExito($respuesta, 'leer la hoja');

        return $respuesta->json('values') ?? [];
    }

    public function escribir(string $spreadsheetId, array $celdas): void
    {
        if ($celdas === []) {
            return;
        }

        $respuesta = Http::withToken($this->token())->acceptJson()->timeout(60)
            ->post('https://sheets.googleapis.com/v4/spreadsheets/'.rawurlencode($spreadsheetId).'/values:batchUpdate', [
                'valueInputOption' => 'RAW',
                'data' => $celdas,
            ]);

        $this->exigirExito($respuesta, 'escribir en la hoja');
    }

    private function exigirExito(Response $respuesta, string $accion): void
    {
        if ($respuesta->failed()) {
            throw new RuntimeException('Google Sheets no permitió '.$accion.' (HTTP '.$respuesta->status().': '.(string) $respuesta->json('error.message', 'sin detalle').').');
        }
    }

    private function token(): string
    {
        return Cache::remember('asistencia_sheets:token', now()->addMinutes(50), function (): string {
            $respuesta = Http::asForm()->timeout(30)->post(self::TOKEN_URL, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $this->jwt(),
            ]);

            if ($respuesta->failed() || ! is_string($respuesta->json('access_token'))) {
                throw new RuntimeException('Google no entregó el token de la cuenta de servicio (HTTP '.$respuesta->status().').');
            }

            return $respuesta->json('access_token');
        });
    }

    private function jwt(): string
    {
        $cuenta = $this->credenciales();
        $ahora = time();
        $encabezado = $this->base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $cuerpo = $this->base64Url((string) json_encode([
            'iss' => $cuenta['client_email'],
            'scope' => self::ALCANCE,
            'aud' => self::TOKEN_URL,
            'iat' => $ahora,
            'exp' => $ahora + 3600,
        ]));

        $firma = '';

        if (! openssl_sign($encabezado.'.'.$cuerpo, $firma, $cuenta['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('No se pudo firmar con la llave de la cuenta de servicio.');
        }

        return $encabezado.'.'.$cuerpo.'.'.$this->base64Url($firma);
    }

    /** @return array{client_email: string, private_key: string} */
    private function credenciales(): array
    {
        if (! is_string($this->rutaCredenciales) || $this->rutaCredenciales === '' || ! is_file($this->rutaCredenciales)) {
            throw new RuntimeException('Falta el archivo de credenciales de Google (ASISTENCIA_SHEETS_CREDENCIALES).');
        }

        $datos = json_decode((string) file_get_contents($this->rutaCredenciales), true);

        if (! is_array($datos) || ! is_string($datos['client_email'] ?? null) || ! is_string($datos['private_key'] ?? null)) {
            throw new RuntimeException('El archivo de credenciales de Google no tiene client_email y private_key.');
        }

        return ['client_email' => $datos['client_email'], 'private_key' => $datos['private_key']];
    }

    private function base64Url(string $datos): string
    {
        return rtrim(strtr(base64_encode($datos), '+/', '-_'), '=');
    }
}
