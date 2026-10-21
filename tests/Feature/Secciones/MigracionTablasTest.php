<?php

namespace Tests\Feature\Secciones;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * La migración que introduce las tablas por sección conserva lo que ya hay:
 * cada sección con columnas o filas recibe una tabla "Principal" con todo.
 *
 * Revierte (down) y vuelve a aplicar (up) esa migración sobre datos reales
 * del formato anterior. El DDL de MySQL confirma implícitamente, así que esta
 * clase NO usa RefreshDatabase: limpia lo que inserta y deja el esquema como
 * estaba.
 */
class MigracionTablasTest extends TestCase
{
    private Migration $migracion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true]);
        $this->migracion = require database_path('migrations/2026_10_22_000000_create_seccion_tablas_table.php');
    }

    protected function tearDown(): void
    {
        if (! Schema::hasTable('seccion_tablas')) {
            $this->migracion->up();
        }

        // Orden por claves foráneas; nada de lo insertado sobrevive a la prueba.
        foreach (['seccion_columnas', 'seccion_filas', 'seccion_importaciones', 'seccion_tablas', 'secciones', 'menu_grupos'] as $tabla) {
            DB::table($tabla)->delete();
        }

        parent::tearDown();
    }

    private function seccion(int $grupo, string $nombre): int
    {
        return DB::table('secciones')->insertGetId([
            'menu_grupo_id' => $grupo, 'nombre' => $nombre, 'slug' => str($nombre)->slug()->toString(), 'orden' => 1,
            'is_visible' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function columna(int $seccion, string $clave): int
    {
        return DB::table('seccion_columnas')->insertGetId([
            'seccion_id' => $seccion, 'nombre' => strtoupper($clave), 'clave' => $clave, 'tipo' => 'texto',
            'orden' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function fila(int $seccion, ?string $borrada = null): int
    {
        return DB::table('seccion_filas')->insertGetId([
            'seccion_id' => $seccion, 'datos' => '{"no":"1"}', 'texto_busqueda' => '1',
            'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $borrada,
        ]);
    }

    public function test_existing_columns_and_rows_move_to_a_default_table_per_section(): void
    {
        $this->migracion->down();
        $this->assertFalse(Schema::hasTable('seccion_tablas'));
        $this->assertFalse(Schema::hasColumn('seccion_filas', 'seccion_tabla_id'));

        $grupo = DB::table('menu_grupos')->insertGetId(['nombre' => 'RR.HH.', 'slug' => 'rrhh', 'orden' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $conDatos = $this->seccion($grupo, 'Rol horarios');
        $soloFilasBorradas = $this->seccion($grupo, 'Con baja');
        $vacia = $this->seccion($grupo, 'Vacía');
        $columna = $this->columna($conDatos, 'no');
        $filas = [$this->fila($conDatos), $this->fila($conDatos), $this->fila($conDatos, '2026-10-01 10:00:00')];
        $filaBorrada = $this->fila($soloFilasBorradas, '2026-10-02 10:00:00');

        $this->migracion->up();

        $tablas = DB::table('seccion_tablas')->get()->keyBy('seccion_id');
        $this->assertCount(2, $tablas, 'solo las secciones con columnas o filas reciben tabla');
        $this->assertFalse($tablas->has($vacia));
        $this->assertSame('Principal', $tablas[$conDatos]->nombre);

        $this->assertSame($tablas[$conDatos]->id, DB::table('seccion_columnas')->where('id', $columna)->value('seccion_tabla_id'));
        $this->assertSame([$tablas[$conDatos]->id], DB::table('seccion_filas')->whereIn('id', $filas)->pluck('seccion_tabla_id')->unique()->map(fn ($id) => (int) $id)->all());
        $this->assertSame($tablas[$soloFilasBorradas]->id, (int) DB::table('seccion_filas')->where('id', $filaBorrada)->value('seccion_tabla_id'));
        // seccion_id se conserva (asistente, dashboard, documentos).
        $this->assertSame($conDatos, (int) DB::table('seccion_filas')->where('id', $filas[0])->value('seccion_id'));
    }

    public function test_after_the_migration_the_key_is_unique_per_table_and_the_link_is_mandatory(): void
    {
        $grupo = DB::table('menu_grupos')->insertGetId(['nombre' => 'G', 'slug' => 'g', 'orden' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $seccion = $this->seccion($grupo, 'Dos tablas');
        $ahora = now();
        $uno = DB::table('seccion_tablas')->insertGetId(['seccion_id' => $seccion, 'nombre' => 'Uno', 'orden' => 1, 'created_at' => $ahora, 'updated_at' => $ahora]);
        $dos = DB::table('seccion_tablas')->insertGetId(['seccion_id' => $seccion, 'nombre' => 'Dos', 'orden' => 2, 'created_at' => $ahora, 'updated_at' => $ahora]);
        $nueva = fn (int $tabla) => DB::table('seccion_columnas')->insert([
            'seccion_id' => $seccion, 'seccion_tabla_id' => $tabla, 'nombre' => 'NO', 'clave' => 'no', 'tipo' => 'texto',
            'orden' => 1, 'created_at' => $ahora, 'updated_at' => $ahora,
        ]);

        $nueva($uno);
        $nueva($dos);
        $this->assertSame(2, DB::table('seccion_columnas')->where('clave', 'no')->count());

        $this->expectException(QueryException::class);
        $nueva($uno);
    }

    public function test_a_row_without_table_is_rejected_by_the_database(): void
    {
        $grupo = DB::table('menu_grupos')->insertGetId(['nombre' => 'G', 'slug' => 'g', 'orden' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $seccion = $this->seccion($grupo, 'Sin tabla');

        $this->expectException(QueryException::class);
        DB::table('seccion_filas')->insert(['seccion_id' => $seccion, 'datos' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    }
}
