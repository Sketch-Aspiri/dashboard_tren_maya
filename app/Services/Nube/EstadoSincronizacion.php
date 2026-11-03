<?php

namespace App\Services\Nube;

use App\Enums\EstadoCuentaNube;
use App\Enums\EstadoOrigen;
use App\Jobs\SincronizarOrigenJob;
use App\Models\NubeCuenta;
use App\Models\SeccionMapeo;
use App\Models\SeccionOrigen;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as ColeccionBase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lo que muestran la página "Estado de sincronización" y el aviso global:
 * cuentas por reconectar, orígenes que esperan un mapeo y cambios masivos
 * retenidos. Solo Administrador y Jefe de Zona lo consultan.
 */
final class EstadoSincronizacion
{
    private const MAX_ORIGENES = 200;

    private const MAX_FALLOS = 20;

    /**
     * Cuántos asuntos necesitan acción humana; todo en 0 (o rol sin acceso) = sin aviso.
     *
     * @return array{cuentas: int, mapeos: int, retenidos: int, total: int}
     */
    public function avisos(?User $usuario): array
    {
        if ($usuario === null || ! $usuario->hasAnyRole(Roles::JEFATURA)) {
            return ['cuentas' => 0, 'mapeos' => 0, 'retenidos' => 0, 'total' => 0];
        }

        $cuentas = NubeCuenta::query()->where('estado', EstadoCuentaNube::RequiereReconexion->value)->count();
        $mapeos = SeccionOrigen::query()->where('estado', EstadoOrigen::PendienteMapeo->value)->count();
        $retenidos = SeccionMapeo::query()->whereNotNull('retenido')->count();

        return ['cuentas' => $cuentas, 'mapeos' => $mapeos, 'retenidos' => $retenidos, 'total' => $cuentas + $mapeos + $retenidos];
    }

    /**
     * @return array{cuentas: Collection<int, NubeCuenta>, origenes: Collection<int, SeccionOrigen>, fallos: ColeccionBase<int, array{id: int, cola: string, clase: string, fallo_en: string}>}
     */
    public function panel(): array
    {
        return [
            'cuentas' => NubeCuenta::query()->orderBy('proveedor')->orderBy('cuenta_email')->get(),
            'origenes' => SeccionOrigen::query()
                ->with(['seccion:id,nombre,slug,menu_grupo_id', 'cuenta:id,proveedor,cuenta_email,estado', 'mapeos.tabla'])
                ->withCount('mapeos')
                ->orderBy('id')
                ->limit(self::MAX_ORIGENES)
                ->get(),
            'fallos' => $this->fallosRecientes(),
        ];
    }

    /**
     * Últimos trabajos de sincronización fallidos. Sin el payload (lleva los
     * datos del trabajo) ni el mensaje completo: solo cola, clase de la
     * excepción y fecha.
     *
     * @return ColeccionBase<int, array{id: int, cola: string, clase: string, fallo_en: string}>
     */
    private function fallosRecientes(): ColeccionBase
    {
        return DB::table('failed_jobs')
            ->where('queue', SincronizarOrigenJob::COLA)
            ->where('payload', 'like', '%'.class_basename(SincronizarOrigenJob::class).'%')
            ->orderByDesc('id')
            ->limit(self::MAX_FALLOS)
            ->get(['id', 'queue', 'exception', 'failed_at'])
            ->map(fn (object $fallo): array => [
                'id' => (int) $fallo->id,
                'cola' => (string) $fallo->queue,
                'clase' => Str::limit(Str::before(Str::before((string) $fallo->exception, ':'), "\n"), 120),
                'fallo_en' => (string) $fallo->failed_at,
            ]);
    }
}
