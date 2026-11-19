<?php

namespace App\Services;

use App\Models\Equipo;
use App\Models\Estacion;
use App\Models\User;
use App\Services\Ecosistema\PuenteCliente;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Equipos por estación: crea/edita (estaciones donde existe y grupos del menú que usa) y los vincula con Tickets
 * por el puente. Si Tickets no responde, el equipo se guarda igual y se puede sincronizar después.
 */
class EquipoService
{
    public function __construct(private readonly PuenteCliente $puente) {}

    /**
     * @param  array{nombre: string, clave?: string, estaciones?: list<int>, grupos?: list<int>}  $datos
     */
    public function guardar(?Equipo $equipo, array $datos): Equipo
    {
        $equipo = DB::transaction(function () use ($equipo, $datos): Equipo {
            $equipo ??= new Equipo(['clave' => $datos['clave']]);
            $equipo->nombre = $datos['nombre'];
            $equipo->save();

            $equipo->estaciones()->sync($datos['estaciones'] ?? []);

            // Un grupo del menú es de un solo equipo: al asignarlo aquí se le quita al que lo tuviera.
            $grupos = array_values(array_unique($datos['grupos'] ?? []));
            DB::table('equipo_menu_grupo')->whereIn('menu_grupo_id', $grupos)->where('equipo_id', '!=', $equipo->id)->delete();
            $equipo->menuGrupos()->sync($grupos);

            activity('equipos')->performedOn($equipo)->event('equipo_configurado')
                ->withProperties(['estaciones' => $equipo->estaciones()->pluck('estaciones.id')->all(), 'grupos' => $grupos])
                ->log('equipo_configurado');

            return $equipo;
        });

        Cache::forget(Equipo::CACHE);

        return $equipo;
    }

    /** @throws ValidationException si todavía hay cuentas con ese equipo */
    public function eliminar(Equipo $equipo): void
    {
        if (User::query()->where('area', $equipo->clave)->exists()) {
            throw ValidationException::withMessages(['equipo' => __('Hay cuentas con este equipo: cámbiales el equipo antes de eliminarlo.')]);
        }

        activity('equipos')->performedOn($equipo)->event('equipo_eliminado')->log('equipo_eliminado');
        $equipo->delete();
        Cache::forget(Equipo::CACHE);
    }

    /** Crea en Tickets el equipo central y sus subequipos en las estaciones del equipo. true si Tickets lo aceptó. */
    public function sincronizarConTickets(Equipo $equipo): bool
    {
        $estaciones = $equipo->estaciones()->whereNotNull('codigo')->orderBy('orden')->get()
            ->map(fn (Estacion $e): array => ['codigo' => (string) $e->codigo, 'nombre' => $e->nombre])
            ->values()->all();

        $respuesta = $this->puente->postConEstado('/interno/equipos', [
            'clave' => $equipo->clave,
            'nombre' => $equipo->nombre,
            'estaciones' => $estaciones,
        ]);

        return $respuesta['estado'] >= 200 && $respuesta['estado'] < 300;
    }
}
