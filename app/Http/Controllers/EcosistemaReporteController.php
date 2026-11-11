<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReportarFallaRequest;
use App\Services\Ecosistema\ContextoFalla;
use App\Services\Ecosistema\PuenteCliente;
use Illuminate\Http\RedirectResponse;

/**
 * "Reportar una falla" (Temayin): guarda el contexto y manda a la persona al
 * formulario de Soporte de Tickets, que lo recupera por el puente y lo
 * prellena. Sin puente configurado abre el formulario de Soporte vacío
 * (comportamiento anterior).
 */
class EcosistemaReporteController extends Controller
{
    public function __invoke(ReportarFallaRequest $request, ContextoFalla $contextos, PuenteCliente $puente): RedirectResponse
    {
        if (! $puente->activo()) {
            return redirect()->away((string) config('services.tickets.soporte_url'));
        }

        $desde = $request->desde();
        $id = $contextos->guardar($request->user(), $desde, $request);

        activity('ecosistema')->causedBy($request->user())->event('reporte_falla')
            ->withProperties(['desde' => $desde])
            ->log('Reporte de falla enviado a Soporte en Tickets.');

        return redirect()->away(config('ecosistema.urls.tickets').'/tickets/create?prefill='.$id);
    }
}
