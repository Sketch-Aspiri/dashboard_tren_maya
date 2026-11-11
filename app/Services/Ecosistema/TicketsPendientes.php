<?php

namespace App\Services\Ecosistema;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Tarjeta "Mis pendientes en Tickets": el resumen que Tickets calcula con las
 * mismas consultas de su vista "Mis pendientes" (alcance propio, solo para
 * cuentas vinculadas). Se guarda 60 s por persona, también cuando Tickets no
 * responde, para no frenar cada carga del dashboard.
 *
 * Devuelve null si el puente no está configurado (la tarjeta no se muestra);
 * ['disponible' => false] si Tickets no respondió; ['vinculada' => false] si la
 * cuenta de Tickets aún no está unida a esta; o los conteos y los 5 más urgentes
 * con URLs absolutas a Tickets.
 */
final class TicketsPendientes
{
    public function __construct(private readonly PuenteCliente $puente) {}

    /**
     * @return array<string, mixed>|null
     */
    public function para(User $user): ?array
    {
        if (! $this->puente->activo()) {
            return null;
        }

        // El correo del Dashboard se puede editar sin verificar: sin vínculo no se pregunta por él.
        if ($user->sso_vinculado_at === null) {
            return ['disponible' => true, 'vinculada' => false, 'url' => config('ecosistema.urls.tickets').'/tickets/pending'];
        }

        return Cache::remember('ecosistema:pendientes:'.$user->getKey().':'.hash('sha256', mb_strtolower($user->email)), (int) config('ecosistema.puente.cache_pendientes', 60), function () use ($user): array {
            $datos = $this->puente->get('/interno/pendientes', ['correo' => mb_strtolower($user->email)]);

            return $datos === null ? ['disponible' => false] : $this->normalizar($datos);
        });
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function normalizar(array $datos): array
    {
        $tickets = (string) config('ecosistema.urls.tickets');

        if (($datos['linked'] ?? false) !== true) {
            return ['disponible' => true, 'vinculada' => false, 'url' => $tickets.'/tickets/pending'];
        }

        $conteos = (array) ($datos['counts'] ?? []);

        return [
            'disponible' => true,
            'vinculada' => true,
            'conteos' => [
                'tickets' => (int) ($conteos['tickets'] ?? 0),
                'actividades' => (int) ($conteos['activities'] ?? 0),
                'vencidos' => (int) ($conteos['overdue'] ?? 0),
                'en_revision' => (int) ($conteos['in_review'] ?? 0),
            ],
            'urgentes' => collect((array) ($datos['urgent'] ?? []))
                ->filter(fn ($fila) => is_array($fila) && is_string($fila['folio'] ?? null) && is_string($fila['title'] ?? null))
                ->take(5)
                ->map(fn (array $fila) => [
                    'tipo' => ($fila['type'] ?? '') === 'activity' ? 'actividad' : 'ticket',
                    'folio' => mb_substr($fila['folio'], 0, 30),
                    'titulo' => mb_substr($fila['title'], 0, 255),
                    'estado' => mb_substr((string) ($fila['status'] ?? ''), 0, 40),
                    'prioridad' => mb_substr((string) ($fila['priority'] ?? ''), 0, 40),
                    'vence' => is_string($fila['due_date'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fila['due_date']) ? $fila['due_date'] : null,
                    'vencido' => ($fila['overdue'] ?? false) === true,
                    'url' => $tickets.$this->rutaRelativa($fila['url'] ?? null),
                ])
                ->values()
                ->all(),
            'url' => $tickets.$this->rutaRelativa($datos['pending_url'] ?? null, '/tickets/pending'),
        ];
    }

    /**
     * Solo rutas relativas del propio Tickets (nunca otro dominio).
     */
    private function rutaRelativa(mixed $ruta, string $omision = '/tickets/pending'): string
    {
        return is_string($ruta) && preg_match('#^/[A-Za-z0-9/_-]{0,200}$#D', $ruta) === 1 ? $ruta : $omision;
    }
}
