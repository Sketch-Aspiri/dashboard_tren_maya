<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Aviso del Dashboard para la bandeja local (campana). Solo canal `database`: el push a la PWA de
 * Tickets lo manda aparte AvisosEcosistema por el puente. Texto corto y sin datos sensibles.
 */
final class AvisoDashboard extends Notification
{
    public const MODULOS = ['insumos', 'asistencia', 'horarios', 'vacaciones', 'agua', 'cumpleanos', 'ptar', 'otro'];

    public function __construct(
        private readonly string $modulo,
        private readonly string $titulo,
        private readonly string $cuerpo,
        private readonly string $ruta,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        return [
            'modulo' => in_array($this->modulo, self::MODULOS, true) ? $this->modulo : 'otro',
            'titulo' => $this->titulo,
            'cuerpo' => $this->cuerpo,
            'url' => $this->ruta,
        ];
    }
}
