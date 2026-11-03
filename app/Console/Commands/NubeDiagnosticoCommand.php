<?php

namespace App\Console\Commands;

use App\Enums\EstadoCuentaNube;
use App\Enums\EstadoOrigen;
use App\Enums\ProveedorNube;
use App\Models\NubeCuenta;
use App\Models\SeccionOrigen;
use App\Services\Nube\CredencialesNubeService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Diagnóstico de la sincronización con la nube para el VPS: solo lectura.
 * Nunca imprime secretos (de las credenciales solo dice si existen).
 */
class NubeDiagnosticoCommand extends Command
{
    /** Última vez que el scheduler corrió (lo escribe una tarea de routes/console.php cada minuto). */
    public const CLAVE_CRON = 'nube:diagnostico:ultimo-cron';

    /** Última vez que un worker terminó un trabajo (lo escribe un listener de AppServiceProvider). */
    public const CLAVE_WORKER = 'nube:diagnostico:ultimo-trabajo';

    /** Pasado este tiempo sin latido del scheduler se considera que el cron no corre. */
    private const MAX_SEGUNDOS_CRON = 180;

    protected $signature = 'nube:diagnostico';

    protected $description = 'Revisa (solo lectura) si la sincronización con la nube está lista: credenciales, cuentas, orígenes, cron, worker, disco y cola';

    public function handle(CredencialesNubeService $credenciales): int
    {
        $this->seccion('Credenciales OAuth');

        foreach (ProveedorNube::cases() as $proveedor) {
            $this->line(sprintf('  %-34s credenciales: %s', $proveedor->label(), $credenciales->estado($proveedor)['configurado'] ? 'sí' : 'no'));
        }

        $this->seccion('Cuentas conectadas');
        $this->cuentas();

        $this->seccion('Orígenes por estado');

        foreach (EstadoOrigen::cases() as $estado) {
            $this->line(sprintf('  %-26s %d', $estado->label(), SeccionOrigen::query()->where('estado', $estado->value)->count()));
        }

        $this->seccion('Procesos en segundo plano');
        $this->procesos();

        $this->seccion('Servidor');
        $this->line('  QUEUE_CONNECTION: '.config('queue.default'));
        $this->line('  Disco libre: '.$this->discoLibre());

        return self::SUCCESS;
    }

    private function cuentas(): void
    {
        $cuentas = NubeCuenta::query()->orderBy('proveedor')->get(['proveedor', 'estado']);

        if ($cuentas->isEmpty()) {
            $this->line('  (ninguna)');

            return;
        }

        foreach (ProveedorNube::cases() as $proveedor) {
            $delProveedor = $cuentas->where('proveedor', $proveedor);

            if ($delProveedor->isEmpty()) {
                continue;
            }

            $this->line(sprintf(
                '  %-34s %d activa(s), %d por reconectar',
                $proveedor->label(),
                $delProveedor->where('estado', EstadoCuentaNube::Activa)->count(),
                $delProveedor->where('estado', EstadoCuentaNube::RequiereReconexion)->count(),
            ));
        }
    }

    private function procesos(): void
    {
        $cron = $this->hace(Cache::get(self::CLAVE_CRON));
        $this->line('  Cron (schedule:run): '.($cron === null
            ? 'SIN LATIDO: el cron de schedule:run no ha corrido (ver deploy/vps/cron.txt)'
            : ($cron['segundos'] <= self::MAX_SEGUNDOS_CRON ? "activo (último latido {$cron['texto']})" : "DETENIDO (último latido {$cron['texto']})")));

        $worker = $this->hace(Cache::get(self::CLAVE_WORKER));
        $this->line('  Worker de cola: '.($worker === null
            ? 'sin trabajos procesados todavía (si ya hay orígenes, revisa supervisor)'
            : "último trabajo procesado {$worker['texto']}"));

        $this->line('  Trabajos en espera: '.$this->enEspera().' · fallidos: '.$this->contar('failed_jobs'));
    }

    private function enEspera(): string
    {
        return config('queue.default') === 'database' ? $this->contar('jobs') : 'n/d con '.config('queue.default');
    }

    private function contar(string $tabla): string
    {
        try {
            return Schema::hasTable($tabla) ? (string) DB::table($tabla)->count() : 'sin tabla';
        } catch (Throwable) {
            return 'n/d';
        }
    }

    private function discoLibre(): string
    {
        $libre = @disk_free_space(Storage::disk((string) config('secciones.disco'))->path(''));

        return $libre === false ? 'n/d' : number_format($libre / 1024 ** 3, 1).' GB (reserva mínima '.config('secciones.nube.reserva_disco_gb').' GB)';
    }

    /**
     * @return array{segundos: int, texto: string}|null
     */
    private function hace(mixed $marca): ?array
    {
        if (! is_numeric($marca)) {
            return null;
        }

        $momento = Carbon::createFromTimestamp((int) $marca);

        return ['segundos' => max(0, (int) $momento->diffInSeconds(now(), true)), 'texto' => $momento->diffForHumans()];
    }

    private function seccion(string $titulo): void
    {
        $this->newLine();
        $this->info($titulo);
    }
}
