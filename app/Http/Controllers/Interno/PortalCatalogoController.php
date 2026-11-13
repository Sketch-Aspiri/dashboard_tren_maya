<?php

namespace App\Http\Controllers\Interno;

use App\Http\Controllers\Controller;
use App\Models\Estacion;
use App\Services\Ecosistema\PortalAprovisionamiento;
use App\Support\Roles;
use Illuminate\Http\JsonResponse;

/**
 * Catálogo que el portal muestra al aprobar una cuenta: roles y estaciones donde puede ubicarse a la persona.
 * Sin datos de usuarios.
 */
class PortalCatalogoController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'sistema' => 'dashboard',
            'roles' => collect(PortalAprovisionamiento::ROLES)->map(fn (string $nombre, string $clave): array => [
                'clave' => $clave,
                'nombre' => $nombre,
                'requiere_estacion' => $nombre === Roles::ESTACION,
            ])->values()->all(),
            // Todas las del catálogo, incluido "Edificio Zonal Este": una cuenta Estación también puede ligarse a él.
            'estaciones' => Estacion::query()->orderBy('orden')->get(['id', 'nombre', 'codigo'])
                ->map(fn (Estacion $estacion): array => [
                    'id' => $estacion->id,
                    'nombre' => $estacion->nombre,
                    'codigo' => $estacion->codigo,
                ])->all(),
        ]);
    }
}
