<?php

namespace App\Services;

use App\Models\Empleado;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Importación por arrastre del Excel "Base de datos ZONA ORIENTE" hacia
 * Personal (modelo estructurado, del que dependen Asistencia y sus
 * oficios). Reutiliza los importadores de consola ya probados:
 *   app:import-agenda-zona-oriente, app:import-cuadrillas-mantenimiento y
 *   app:link-empleados-estaciones.
 *
 * La vista previa corre esos mismos comandos dentro de una transacción que
 * se revierte: cuenta exactamente lo que pasaría, sin dejar cambios.
 */
final class PersonalImportacionService
{
    private const DISCO = 'local';

    private const DIRECTORIO = 'imports/personal-pendiente';

    public function guardarPendiente(UploadedFile $archivo): string
    {
        return $archivo->storeAs(self::DIRECTORIO, Str::uuid()->toString().'.xlsx', self::DISCO);
    }

    /**
     * @return array{creados: int, actualizados: int, total_despues: int}
     */
    public function vistaPrevia(string $ruta): array
    {
        DB::beginTransaction();

        try {
            return $this->ejecutar($ruta);
        } finally {
            DB::rollBack();
        }
    }

    /**
     * @return array{creados: int, actualizados: int, total_despues: int}
     */
    public function aplicar(string $ruta, User $usuario): array
    {
        $resumen = DB::transaction(fn () => $this->ejecutar($ruta));

        activity('empleado')
            ->causedBy($usuario)
            ->event('personal_importado')
            ->withProperties($resumen)
            ->log('Importación del Excel de personal desde la pantalla.');

        $this->descartar($ruta);

        return $resumen;
    }

    public function descartar(string $ruta): void
    {
        Storage::disk(self::DISCO)->delete($ruta);
    }

    public function existe(?string $ruta): bool
    {
        return $ruta !== null
            && str_starts_with($ruta, self::DIRECTORIO.'/')
            && Storage::disk(self::DISCO)->exists($ruta);
    }

    /**
     * @return array{creados: int, actualizados: int, total_despues: int}
     */
    private function ejecutar(string $ruta): array
    {
        $absoluta = Storage::disk(self::DISCO)->path($ruta);
        $antes = Empleado::query()->count();
        $inicio = CarbonImmutable::now()->subSecond();

        $codigo = Artisan::call('app:import-agenda-zona-oriente', ['path' => $absoluta]);

        if ($codigo !== 0) {
            throw ValidationException::withMessages([
                'archivo' => __('El archivo no tiene la hoja "Base de Datos Zona Oriente" o no se pudo leer.'),
            ]);
        }

        Artisan::call('app:import-cuadrillas-mantenimiento', ['path' => $absoluta]);
        Artisan::call('app:link-empleados-estaciones');

        $despues = Empleado::query()->count();

        return [
            'creados' => $despues - $antes,
            'actualizados' => Empleado::query()->where('updated_at', '>=', $inicio)->where('created_at', '<', $inicio)->count(),
            'total_despues' => $despues,
        ];
    }
}
