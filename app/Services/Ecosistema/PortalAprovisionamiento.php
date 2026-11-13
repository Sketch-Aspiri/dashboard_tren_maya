<?php

namespace App\Services\Ecosistema;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Support\Facades\DB;

/**
 * Crea la cuenta que el portal aprobó: con rol y estación, vinculada al portal y con la MISMA contraseña (llega
 * su hash bcrypt por el puente firmado).
 *
 * La autoridad es la aprobación del portal y la firma del puente, no un usuario de aquí. Nunca toca una cuenta
 * existente con ese correo: el correo no se verifica, así que emparejarla solo por él permitiría a quien lo
 * escriba en el portal entrar a la cuenta de otra persona. Esas cuentas se vinculan con su contraseña la primera
 * vez que entran por el inicio único.
 */
final class PortalAprovisionamiento
{
    public const CREADA = 'creada';

    public const YA = 'ya_aprovisionada';

    public const CORREO_EXISTENTE = 'correo_existente';

    /** Clave del portal => nombre del rol (spatie) en el Dashboard. */
    public const ROLES = [
        'jefe_zona' => Roles::JEFE_ZONA,
        'administrador' => Roles::ADMINISTRADOR,
        'edificio' => Roles::EDIFICIO,
        'estacion' => Roles::ESTACION,
    ];

    /**
     * @param  array{portal_user_id: string, name: string, email: string, password_hash: string, role: string, estacion_id?: int|null}  $datos
     * @return array{estado: string, user_id?: int}
     */
    public function aprovisionar(array $datos): array
    {
        $correo = mb_strtolower($datos['email']);
        $rol = self::ROLES[$datos['role']];

        return DB::transaction(function () use ($datos, $correo, $rol): array {
            $existente = User::query()->whereRaw('LOWER(email) = ?', [$correo])->lockForUpdate()->first();

            if ($existente !== null) {
                // Reintento del mismo alta: idempotente. Cualquier otra cuenta con ese correo se respeta.
                return $existente->portal_user_id === $datos['portal_user_id']
                    ? ['estado' => self::YA, 'user_id' => $existente->id]
                    : ['estado' => self::CORREO_EXISTENTE];
            }

            $user = new User([
                'name' => trim($datos['name']),
                'email' => $correo,
                'password' => bin2hex(random_bytes(16)),
                'estacion_id' => $rol === Roles::ESTACION ? ($datos['estacion_id'] ?? null) : null,
            ]);
            $user->forceFill([
                'email_verified_at' => now(),
                'portal_user_id' => $datos['portal_user_id'],
                'sso_vinculado_at' => now(),
            ])->save();

            // El cast `hashed` volvería a cifrar un hash con otro costo: se escribe tal cual llegó.
            DB::table('users')->where('id', $user->id)->update(['password' => $datos['password_hash']]);

            $user->assignRole($rol);

            activity('ecosistema')->performedOn($user)->event('aprovisionada_por_portal')
                ->withProperties(['rol' => $rol, 'estacion_id' => $user->estacion_id])->log('aprovisionada_por_portal');

            return ['estado' => self::CREADA, 'user_id' => $user->id];
        });
    }
}
