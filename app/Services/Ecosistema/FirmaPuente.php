<?php

namespace App\Services\Ecosistema;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Firma del puente interno servidor a servidor con Tickets. Es la misma
 * clase que App\Services\Ecosystem\BridgeSignature de Tickets, con el mismo
 * formato (no cambiar una sin la otra):
 *
 *   firma = hex(hmac_sha256(secreto, MÉTODO \n /ruta \n consulta_normalizada \n sistema \n fecha \n nonce \n sha256(cuerpo)))
 *
 * `consulta_normalizada` son los parámetros ordenados por nombre y
 * codificados RFC 3986. Encabezados: X-Puente-Sistema, X-Puente-Fecha (unix),
 * X-Puente-Nonce (un solo uso) y X-Puente-Firma.
 *
 * Hay un secreto por dirección: lo que llega se verifica con
 * `secreto_entrada` y lo que sale se firma con `secreto_salida`. Los
 * secretos nunca viajan ni aparecen en errores.
 */
final class FirmaPuente
{
    public const SISTEMA = 'X-Puente-Sistema';

    public const FECHA = 'X-Puente-Fecha';

    public const NONCE = 'X-Puente-Nonce';

    public const FIRMA = 'X-Puente-Firma';

    public function entradaConfigurada(): bool
    {
        return strlen((string) config('ecosistema.puente.secreto_entrada')) >= 32;
    }

    public function salidaConfigurada(): bool
    {
        return strlen((string) config('ecosistema.puente.secreto_salida')) >= 32;
    }

    /**
     * Encabezados para una llamada saliente.
     *
     * @param  array<string, mixed>  $consulta
     * @return array<string, string>
     */
    public function encabezados(string $metodo, string $ruta, array $consulta = [], string $cuerpo = ''): array
    {
        $sistema = (string) config('ecosistema.sistema');
        $fecha = (string) now()->getTimestamp();
        $nonce = Str::random(40);

        return [
            self::SISTEMA => $sistema,
            self::FECHA => $fecha,
            self::NONCE => $nonce,
            self::FIRMA => $this->calcular((string) config('ecosistema.puente.secreto_salida'), $metodo, $ruta, $consulta, $sistema, $fecha, $nonce, $cuerpo),
        ];
    }

    /**
     * Si la petición entrante es auténtica y nueva devuelve el sistema que
     * llama; si no, null.
     */
    public function verificar(Request $request): ?string
    {
        if (! $this->entradaConfigurada()) {
            return null;
        }

        $sistema = (string) $request->header(self::SISTEMA, '');
        $fecha = (string) $request->header(self::FECHA, '');
        $nonce = (string) $request->header(self::NONCE, '');
        $firma = (string) $request->header(self::FIRMA, '');

        if ($sistema === config('ecosistema.sistema')
            || ! array_key_exists($sistema, (array) config('ecosistema.urls'))
            || preg_match('/^\d{1,12}$/D', $fecha) !== 1
            || preg_match('/^[A-Za-z0-9]{20,64}$/D', $nonce) !== 1
            || abs(now()->getTimestamp() - (int) $fecha) > (int) config('ecosistema.puente.ventana', 300)) {
            return null;
        }

        $esperada = $this->calcular(
            (string) config('ecosistema.puente.secreto_entrada'),
            $request->getMethod(),
            '/'.ltrim($request->path(), '/'),
            $request->query->all(),
            $sistema,
            $fecha,
            $nonce,
            (string) $request->getContent(),
        );

        if (! hash_equals($esperada, $firma)) {
            return null;
        }

        // Un solo uso, durante más tiempo que la ventana aceptada.
        $ventana = (int) config('ecosistema.puente.ventana', 300);

        return Cache::add('ecosistema:puente:nonce:'.hash('sha256', $sistema.'|'.$nonce), true, $ventana * 2 + 60) ? $sistema : null;
    }

    public function portalConfigurado(): bool
    {
        return strlen((string) config('ecosistema.puente.portal.secreto')) >= 32;
    }

    /**
     * Petición del PORTAL: mismo formato, pero con su propio secreto y solo del sistema `portal` (que no tiene
     * por qué estar en `ecosistema.urls`).
     */
    public function verificarPortal(Request $request): bool
    {
        if (! $this->portalConfigurado()) {
            return false;
        }

        $fecha = (string) $request->header(self::FECHA, '');
        $nonce = (string) $request->header(self::NONCE, '');
        $firma = (string) $request->header(self::FIRMA, '');

        if ((string) $request->header(self::SISTEMA, '') !== 'portal'
            || preg_match('/^\d{1,12}$/D', $fecha) !== 1
            || preg_match('/^[A-Za-z0-9]{20,64}$/D', $nonce) !== 1
            || abs(now()->getTimestamp() - (int) $fecha) > (int) config('ecosistema.puente.ventana', 300)) {
            return false;
        }

        $esperada = $this->calcular(
            (string) config('ecosistema.puente.portal.secreto'),
            $request->getMethod(),
            '/'.ltrim($request->path(), '/'),
            $request->query->all(),
            'portal',
            $fecha,
            $nonce,
            (string) $request->getContent(),
        );

        if (! hash_equals($esperada, $firma)) {
            return false;
        }

        $ventana = (int) config('ecosistema.puente.ventana', 300);

        return Cache::add('ecosistema:puente:portal:nonce:'.hash('sha256', $nonce), true, $ventana * 2 + 60);
    }

    /**
     * @param  array<string, mixed>  $consulta
     */
    private function calcular(string $secreto, string $metodo, string $ruta, array $consulta, string $sistema, string $fecha, string $nonce, string $cuerpo): string
    {
        ksort($consulta);

        return hash_hmac('sha256', implode("\n", [
            strtoupper($metodo),
            '/'.ltrim($ruta, '/'),
            http_build_query($consulta, '', '&', PHP_QUERY_RFC3986),
            $sistema,
            $fecha,
            $nonce,
            hash('sha256', $cuerpo),
        ]), $secreto);
    }
}
