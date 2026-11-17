<?php

namespace App\Services;

use App\Models\User;

/** Resumen y destino seguro de la bandeja de notificaciones del Dashboard. */
final class NotificationCenter
{
    /** @return array{unread: int} */
    public function summary(User $user): array
    {
        return ['unread' => $user->unreadNotifications()->count()];
    }

    /**
     * Solo rutas internas relativas ("/horarios"); cualquier otra cosa (esquema, host, "//", barras
     * invertidas, caracteres de control) cae a la bandeja para no abrir redirecciones a sitios externos.
     */
    public function targetUrl(?string $url): string
    {
        $url = (string) $url;

        if (preg_match('#^/(?![/\\\\])[^\s\\\\\x00-\x1F\x7F]*$#D', $url) !== 1) {
            return route('notificaciones.index', [], false);
        }

        return $url;
    }
}
