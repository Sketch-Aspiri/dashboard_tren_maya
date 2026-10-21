<?php

namespace Tests\Concerns;

use App\Enums\TipoColumna;
use App\Models\Seccion;
use App\Models\SeccionColumna;
use App\Models\SeccionFila;
use App\Models\SeccionTabla;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Utilidades para las pruebas de secciones dinámicas: usuarios por rol,
 * secciones con columnas/filas y archivos Excel reales generados al vuelo.
 */
trait InteractsWithSecciones
{
    use InteractsWithTwoFactor;

    protected function usuarioConRol(?string $rol): User
    {
        $this->seed(RoleSeeder::class);
        $usuario = User::factory()->create();

        if ($rol !== null) {
            $usuario->assignRole($rol);
        }

        return $usuario;
    }

    protected function comoAdministrador(): User
    {
        return $this->actingAsTwoFactorVerified($this->usuarioConRol('Administrador'));
    }

    /**
     * @param  array<string, TipoColumna>  $columnas  nombre => tipo
     */
    protected function seccionConColumnas(array $columnas = []): Seccion
    {
        $seccion = Seccion::factory()->create();

        foreach (array_keys($columnas) as $orden => $nombre) {
            $seccion->columnas()->create([
                'nombre' => $nombre,
                'clave' => Str::slug($nombre, '_'),
                'tipo' => $columnas[$nombre],
                'orden' => $orden + 1,
            ]);
        }

        return $seccion->fresh('columnas');
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    protected function filaEn(Seccion $seccion, array $datos): SeccionFila
    {
        $fila = new SeccionFila(['datos' => $datos]);
        $fila->forceFill(['seccion_id' => $seccion->id])->save();

        return $fila;
    }

    /**
     * Tabla adicional de una sección, con sus columnas.
     *
     * @param  array<string, TipoColumna>  $columnas  nombre => tipo
     */
    protected function tablaConColumnas(Seccion $seccion, string $nombre, array $columnas = []): SeccionTabla
    {
        $tabla = $seccion->tablas()->create(['nombre' => $nombre, 'orden' => (int) $seccion->tablas()->max('orden') + 1]);

        foreach (array_keys($columnas) as $orden => $columna) {
            $tabla->columnas()->create([
                'seccion_id' => $seccion->id,
                'nombre' => $columna,
                'clave' => Str::slug($columna, '_'),
                'tipo' => $columnas[$columna],
                'orden' => $orden + 1,
            ]);
        }

        return $tabla;
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    protected function filaEnTabla(SeccionTabla $tabla, array $datos): SeccionFila
    {
        $fila = new SeccionFila(['datos' => $datos]);
        $fila->forceFill(['seccion_id' => $tabla->seccion_id, 'seccion_tabla_id' => $tabla->id])->save();

        return $fila;
    }

    protected function columna(Seccion $seccion, string $clave): SeccionColumna
    {
        return $seccion->columnas()->where('clave', $clave)->firstOrFail();
    }

    /**
     * Excel real (.xlsx) con las filas dadas en la primera hoja.
     *
     * @param  list<list<mixed>>  $filas
     */
    protected function excel(array $filas, string $nombre = 'datos.xlsx', string $hoja = 'Hoja1'): UploadedFile
    {
        $libro = new Spreadsheet;
        $libro->getActiveSheet()->setTitle($hoja)->fromArray($filas, null, 'A1', true);

        $ruta = tempnam(sys_get_temp_dir(), 'xlsx');
        (new Xlsx($libro))->save($ruta);

        return new UploadedFile($ruta, $nombre, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
