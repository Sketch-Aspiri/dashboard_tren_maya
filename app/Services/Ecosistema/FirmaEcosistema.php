<?php

namespace App\Services\Ecosistema;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Códigos firmados que viajan entre el Dashboard y Tickets por el navegador
 * (inicio y cierre de sesión únicos). La misma clase existe en Tickets
 * (App\Services\Ecosystem\EcosystemSignature) con el mismo formato:
 *
 *   base64url(json{v, iss, aud, act, email, mfa, state, nonce, exp}) . "." . base64url(hmac_sha256)
 *
 * `state` ata el código de entrada al navegador que lo pidió (evita que un
 * código ajeno, enviado como enlace, abra la sesión de otra persona).
 * `lnk` dice si la cuenta de ORIGEN está vinculada (el correo del Dashboard se
 * puede editar sin verificar): el destino solo abre sesión sin contraseña si
 * ambas cuentas lo están. Sin `lnk`, vale false.
 *
 * El secreto (ECOSISTEMA_SSO_SECRET) es el mismo en ambos .env y nunca sale
 * del servidor. Un código vale para un solo destino, una sola acción, 60 s y
 * una sola vez (el nonce se consume en caché).
 */
final class FirmaEcosistema
{
    public const ENTRAR = 'entrar';

    public const SALIR = 'salir';

    public function configurado(): bool
    {
        return strlen((string) config('ecosistema.sso.secreto')) >= 32;
    }

    public function firmar(string $destino, string $accion, string $email, bool $mfa, ?string $estado = null, bool $vinculada = false): string
    {
        $carga = $this->b64(json_encode([
            'v' => 1,
            'iss' => config('ecosistema.sistema'),
            'aud' => $destino,
            'act' => $accion,
            'email' => mb_strtolower($email),
            'mfa' => $mfa,
            'state' => $estado,
            'lnk' => $vinculada,
            'nonce' => Str::random(32),
            'exp' => now()->addSeconds((int) config('ecosistema.sso.vigencia', 60))->getTimestamp(),
        ], JSON_THROW_ON_ERROR));

        return $carga.'.'.$this->b64(hash_hmac('sha256', $carga, $this->secreto(), true));
    }

    /**
     * Devuelve la carga si el código es auténtico, va dirigido a este sistema,
     * es de la acción esperada, no venció y no se usó antes; si no, null.
     *
     * @return array{iss: string, email: string, mfa: bool, state: ?string, lnk: bool}|null
     */
    public function verificar(?string $codigo, string $accion): ?array
    {
        if (! $this->configurado() || ! is_string($codigo) || strlen($codigo) > 2048 || substr_count($codigo, '.') !== 1) {
            return null;
        }

        [$carga, $firma] = explode('.', $codigo);
        $esperada = $this->b64(hash_hmac('sha256', $carga, $this->secreto(), true));

        if (! hash_equals($esperada, $firma)) {
            return null;
        }

        $datos = json_decode((string) base64_decode(strtr($carga, '-_', '+/'), true), true);

        if (! is_array($datos)
            || ($datos['v'] ?? null) !== 1
            || ($datos['aud'] ?? null) !== config('ecosistema.sistema')
            || ($datos['act'] ?? null) !== $accion
            || ! array_key_exists((string) ($datos['iss'] ?? ''), (array) config('ecosistema.urls'))
            || ($datos['iss'] ?? null) === config('ecosistema.sistema')
            || ! is_string($datos['email'] ?? null)
            || ! is_string($datos['nonce'] ?? null)
            || ! is_int($datos['exp'] ?? null)
            || $datos['exp'] < now()->getTimestamp()) {
            return null;
        }

        // Un solo uso: el primero que lo canjea gana.
        if (! Cache::add('ecosistema:nonce:'.hash('sha256', $datos['nonce']), true, 300)) {
            return null;
        }

        return [
            'iss' => $datos['iss'],
            'email' => $datos['email'],
            'mfa' => (bool) ($datos['mfa'] ?? false),
            'state' => is_string($datos['state'] ?? null) ? $datos['state'] : null,
            'lnk' => ($datos['lnk'] ?? false) === true,
        ];
    }

    private function secreto(): string
    {
        return (string) config('ecosistema.sso.secreto');
    }

    private function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
