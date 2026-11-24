<?php

namespace App\Services\Ecosistema;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Support\Facades\DB;

/**
 * Cambios que el portal hace despues del alta (misma autoridad que el alta: la firma del puente, sin actor
 * humano): estado (baja/reactivacion) y rol/estacion/area. Solo cuentas ya emparejadas por `portal_user_id`;
 * nunca se busca por correo. Una baja conserva datos e historial, cierra las sesiones y no puede dejar al
 * sistema sin Administrador.
 */
final class PortalCuentaSync
{
    public const ACTUALIZADA = 'actualizada';

    public const NO_ENCONTRADA = 'no_encontrada';

    public const ULTIMO_ADMIN = 'ultimo_administrador';

    /**
     * @param  array{estado?: string|null, role?: string|null, estacion_id?: int|null, area?: string|null}  $datos
     * @return array{estado: string, user_id?: int}
     */
    public function sincronizar(string $portalUserId, array $datos): array
    {
        return DB::transaction(function () use ($portalUserId, $datos): array {
            $user = User::query()->where('portal_user_id', $portalUserId)->lockForUpdate()->first();

            if ($user === null) {
                return ['estado' => self::NO_ENCONTRADA];
            }

            $nuevoRol = ! empty($datos['role']) ? PortalAprovisionamiento::ROLES[$datos['role']] : null;
            $baja = ($datos['estado'] ?? null) === 'inactivo';

            if (($baja || ($nuevoRol !== null && $nuevoRol !== Roles::ADMINISTRADOR)) && $this->esUltimoAdministrador($user)) {
                return ['estado' => self::ULTIMO_ADMIN];
            }

            $cambios = [];

            if ($nuevoRol !== null) {
                $cambios['rol'] = [$user->getRoleNames()->all(), $nuevoRol];
                $user->syncRoles([$nuevoRol]);
                $user->forceFill([
                    'estacion_id' => $nuevoRol === Roles::ESTACION ? ($datos['estacion_id'] ?? null) : null,
                    'area' => $nuevoRol === Roles::ESTACION ? ($datos['area'] ?? null) : null,
                    'areas_adicionales' => null,
                ])->save();
            }

            if (isset($datos['estado'])) {
                $desactivado = $baja ? ($user->desactivado_at ?? now()) : null;
                if ($user->desactivado_at?->getTimestamp() !== $desactivado?->getTimestamp()) {
                    $user->forceFill(['desactivado_at' => $desactivado])->save();
                    $cambios['estado'] = $datos['estado'];
                }
                if ($baja) {
                    $this->cerrarSesiones($user);
                }
            }

            activity('ecosistema')->performedOn($user)->event('sincronizada_por_portal')
                ->withProperties(['cambios' => $cambios])->log('sincronizada_por_portal');

            return ['estado' => self::ACTUALIZADA, 'user_id' => $user->id];
        });
    }

    private function esUltimoAdministrador(User $user): bool
    {
        $admins = User::role(Roles::ADMINISTRADOR)->whereNull('desactivado_at')->lockForUpdate()->pluck('users.id');

        return $admins->contains($user->id) && $admins->count() <= 1;
    }

    private function cerrarSesiones(User $user): void
    {
        $user->forceFill(['remember_token' => bin2hex(random_bytes(30))])->saveQuietly();

        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))->table((string) config('session.table', 'sessions'))
                ->where('user_id', $user->getKey())->delete();
        }
    }
}
