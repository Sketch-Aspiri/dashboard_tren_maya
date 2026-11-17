<?php

namespace App\Http\Controllers;

use App\Http\Requests\Notificaciones\IndexNotificacionesRequest;
use App\Services\NotificationCenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Bandeja de notificaciones del usuario. Todo pasa por $user->notifications(): la de otra persona es 404.
 * No requiere Policy propia: la visibilidad ya se decidió al crear el aviso (destinatario) y el destino
 * valida su propia Policy al abrirse.
 */
final class NotificationController extends Controller
{
    public function __construct(private readonly NotificationCenter $center) {}

    public function index(IndexNotificacionesRequest $request): View
    {
        $estado = $request->validated('estado') ?? 'todas';
        $modulo = $request->validated('modulo');

        $query = $request->user()->notifications();

        if ($estado === 'no-leidas') {
            $query->whereNull('read_at');
        }

        if ($modulo !== null) {
            // data es texto JSON: se filtra en la consulta con el operador JSON (valor parametrizado).
            $query->where('data->modulo', $modulo);
        }

        return view('notificaciones.index', [
            'notificaciones' => $query->paginate(20)->withQueryString(),
            'estado' => $estado,
            'modulo' => $modulo,
            'sinLeer' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->center->summary($request->user()),
            'error' => null,
            'meta' => null,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function open(Request $request, string $notification): RedirectResponse
    {
        $item = $request->user()->notifications()->findOrFail($notification);
        $item->markAsRead();

        return redirect($this->center->targetUrl($item->data['url'] ?? null));
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }
}
