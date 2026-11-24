<?php

namespace App\Console\Commands;

use App\Enums\EmpleadoEstatus;
use App\Models\Empleado;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Crea una cuenta individual (rol Personal) por cada persona activa con estación y correo; si ese correo ya tiene cuenta (cualquier rol), la liga a la ficha sin cambiarle el rol. Las
 * contraseñas temporales NO se imprimen: se guardan en un CSV privado para entregarlas en mano.
 * Las cuentas con rol Personal solo llegan a su selección de vacaciones (middleware solo-personal).
 */
class CrearCuentasPersonalCommand extends Command
{
    protected $signature = 'vacaciones:crear-cuentas-personal {--simular : Solo muestra qué se crearía} {--no-empleado=* : Limita a estos números de empleado}';

    protected $description = 'Crea cuentas individuales (rol Personal) desde la tabla empleados';

    public function handle(): int
    {
        $simular = (bool) $this->option('simular');
        $filtro = array_filter((array) $this->option('no-empleado'));

        if (! $simular) {
            Role::findOrCreate(Roles::PERSONAL, 'web');
        }

        $empleados = Empleado::query()->where('estatus', EmpleadoEstatus::Activo->value)->whereNotNull('estacion_id')
            ->when($filtro !== [], fn ($q) => $q->whereIn('no_empleado', $filtro))->orderBy('id')->get();

        $filas = [['no_empleado', 'nombre', 'correo', 'contrasena_temporal']];
        $creadas = 0;
        $omitidas = [];
        $ligadas = [];

        foreach ($empleados as $e) {
            $correo = mb_strtolower(trim((string) $e->correo));

            if ($correo === '' || ! filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                $omitidas[] = "{$e->no_empleado} {$e->nombre_completo}: sin correo válido";

                continue;
            }

            if (User::query()->where('empleado_id', $e->id)->exists()) {
                continue;
            }

            $existente = User::query()->where('email', $correo)->first();

            if ($existente !== null) {
                // Cuenta que ya existe con ese correo (p. ej. de estación o de zona): se liga a la ficha para que
                // también pueda elegir sus vacaciones; conserva su rol y sus permisos.
                if ($existente->empleado_id !== null) {
                    $omitidas[] = "{$e->no_empleado} {$e->nombre_completo}: el correo ya está ligado a otra ficha";

                    continue;
                }

                $ligadas[] = "{$e->no_empleado} {$e->nombre_completo} -> cuenta #{$existente->id} (".$existente->getRoleNames()->implode(', ').')';

                if (! $simular) {
                    $existente->forceFill(['empleado_id' => $e->id])->save();
                }

                continue;
            }

            $creadas++;

            if ($simular) {
                continue;
            }

            $clave = Str::password(14, symbols: false);
            $usuario = new User(['name' => $e->nombre_completo, 'email' => $correo, 'password' => Hash::make($clave)]);
            $usuario->forceFill(['empleado_id' => $e->id, 'email_verified_at' => now()])->save();
            $usuario->assignRole(Roles::PERSONAL);
            $filas[] = [$e->no_empleado, $e->nombre_completo, $correo, $clave];
        }

        if (! $simular && $creadas > 0) {
            $ruta = 'privado/cuentas_personal_'.now()->format('Ymd_His').'.csv';
            Storage::disk('local')->put($ruta, implode("\n", array_map(fn (array $f) => implode(',', array_map(fn ($c) => '"'.str_replace('"', '""', (string) $c).'"', $f)), $filas)));
            $this->info('Contraseñas temporales en storage/app/private/'.$ruta.' (entrégalas y bórralo).');
        }

        $this->info(($simular ? 'Se crearían ' : 'Se crearon ').$creadas.' cuentas.');

        foreach ($ligadas as $linea) {
            $this->line(($simular ? 'Se ligaría: ' : 'Ligada: ').$linea);
        }

        foreach ($omitidas as $linea) {
            $this->warn('Omitida: '.$linea);
        }

        return self::SUCCESS;
    }
}
