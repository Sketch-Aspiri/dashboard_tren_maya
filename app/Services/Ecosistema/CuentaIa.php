<?php

namespace App\Services\Ecosistema;

use App\Models\User;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Cuenta de sistema que figura como `registrado_por` cuando la IA captura algo por el puente (no hay una persona de
 * sesión detrás). Se crea la primera vez que hace falta, con contraseña aleatoria que nadie conoce, SIN rol y SIN
 * estación: aunque alguien lograra iniciar sesión con ella, ninguna Policy le daría acceso a nada. Si ya existe una
 * cuenta con ese correo que no cumple eso (alguien la registró antes), no se usa.
 */
final class CuentaIa
{
    private ?User $usuario = null;

    public function usuario(): User
    {
        if ($this->usuario !== null) {
            return $this->usuario;
        }

        $correo = (string) config('ecosistema.ia.cuenta.correo');
        $existente = User::query()->where('email', $correo)->first() ?? $this->crear($correo);

        if ($existente->roles()->exists() || $existente->estacion_id !== null) {
            throw new DomainException('La cuenta de sistema de la IA no es válida (tiene rol o estación).');
        }

        return $this->usuario = $existente;
    }

    private function crear(string $correo): User
    {
        $usuario = new User(['name' => (string) config('ecosistema.ia.cuenta.nombre'), 'email' => $correo]);
        $usuario->forceFill(['password' => Hash::make(Str::random(64))]);

        try {
            $usuario->save();
        } catch (UniqueConstraintViolationException) {
            // Dos primeras llamadas a la vez: la otra la creó primero.
            return User::query()->where('email', $correo)->firstOrFail();
        }

        return $usuario;
    }
}
