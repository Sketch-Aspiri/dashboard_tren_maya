<?php

namespace Tests\Feature;

use App\Enums\EmpleadoEstatus;
use App\Models\Empleado;
use App\Models\Estacion;
use App\Models\RegistroDiario;
use App\Models\User;
use App\Services\AsistenciaCapturaService;
use App\Services\Ecosistema\CuentaIa;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Herramientas para IA por el puente con Tickets: apagadas por omisión, firmadas, y la captura de asistencia con sus
 * reglas (solo hoy en hora de Cancún, solo el roster activo, sin sobrescribir, fila por fila, cuenta de sistema).
 */
class EcosistemaIaTest extends TestCase
{
    use RefreshDatabase;

    private const SECRETO = 'secreto-tickets-a-dashboard-de-prueba-0123456789';

    private Estacion $estacion;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ecosistema.puente.secreto_entrada' => self::SECRETO,
            'ecosistema.urls.tickets' => 'https://tickets.ejemplo.test',
            'ecosistema.puente.ips' => [],
            'ecosistema.ia.habilitada' => true,
        ]);

        $this->estacion = Estacion::factory()->create(['nombre' => 'Tulum', 'codigo' => '25', 'is_operativa' => true]);
    }

    /**
     * @return array<string, string>
     */
    private function firmado(string $metodo, string $ruta, string $cuerpo = '', string $secreto = self::SECRETO): array
    {
        $fecha = now()->getTimestamp();
        $nonce = Str::random(40);
        $canonica = implode("\n", [$metodo, $ruta, '', 'tickets', (string) $fecha, $nonce, hash('sha256', $cuerpo)]);

        return [
            'X-Puente-Sistema' => 'tickets',
            'X-Puente-Fecha' => (string) $fecha,
            'X-Puente-Nonce' => $nonce,
            'X-Puente-Firma' => hash_hmac('sha256', $canonica, $secreto),
            'Accept' => 'application/json',
        ];
    }

    private function listar(): TestResponse
    {
        // `get` y no `getJson`: este último manda un cuerpo `[]` y Tickets firma un GET con cuerpo vacío.
        return $this->get('/interno/ia/herramientas', $this->firmado('GET', '/interno/ia/herramientas'));
    }

    /**
     * @param  array<string, mixed>  $entrada
     */
    private function ejecutar(string $herramienta, array $entrada = [], string $cliente = 'asistente-escritura'): TestResponse
    {
        $ruta = '/interno/ia/herramientas/'.$herramienta;
        $cuerpo = ['cliente' => $cliente, 'entrada' => $entrada];

        return $this->postJson($ruta, $cuerpo, $this->firmado('POST', $ruta, (string) json_encode($cuerpo)));
    }

    /**
     * @return array<string, mixed>
     */
    private function contenido(TestResponse $respuesta): array
    {
        return (array) json_decode((string) $respuesta->json('contenido'), true);
    }

    private function empleado(array $atributos = []): Empleado
    {
        return Empleado::factory()->create($atributos + ['estacion_id' => $this->estacion->id, 'estatus' => EmpleadoEstatus::Activo->value]);
    }

    // --- Protección ---------------------------------------------------------------------------------------------------------

    public function test_the_routes_do_not_exist_while_the_switch_is_off(): void
    {
        config(['ecosistema.ia.habilitada' => false]);

        $this->listar()->assertNotFound();
        $this->ejecutar('catalogo_dashboard')->assertNotFound();
    }

    public function test_unsigned_or_wrongly_signed_requests_are_rejected(): void
    {
        $this->get('/interno/ia/herramientas', ['Accept' => 'application/json'])->assertUnauthorized();
        $this->get('/interno/ia/herramientas', $this->firmado('GET', '/interno/ia/herramientas', '', 'otro-secreto-que-no-es-el-bueno-0123456789'))->assertUnauthorized();

        // Cuerpo alterado después de firmar.
        $ruta = '/interno/ia/herramientas/capturar_asistencia';
        $firmado = $this->firmado('POST', $ruta, (string) json_encode(['cliente' => 'a', 'entrada' => []]));
        $this->postJson($ruta, ['cliente' => 'a', 'entrada' => ['estacion' => 'Tulum']], $firmado)->assertUnauthorized();

        $this->assertSame(0, RegistroDiario::query()->count());
    }

    public function test_the_tool_list_marks_which_ones_write_and_hides_strict_mode(): void
    {
        $respuesta = $this->listar()->assertOk();
        $herramientas = collect($respuesta->json('herramientas'))->keyBy('name');

        $this->assertEqualsCanonicalizing(
            ['consultar_seccion', 'consultar_personal', 'consultar_asistencia', 'buscar_documentos', 'catalogo_dashboard', 'asistencia_pendiente_hoy', 'capturar_asistencia'],
            $herramientas->keys()->all(),
        );
        $this->assertTrue($herramientas['capturar_asistencia']['escritura']);
        $this->assertSame(['capturar_asistencia'], $herramientas->filter(fn (array $h) => $h['escritura'])->keys()->all());
        $this->assertArrayNotHasKey('strict', $herramientas['consultar_seccion']);
        $this->assertSame('object', $herramientas['consultar_seccion']['inputSchema']['type']);
        // Un esquema sin propiedades debe salir como objeto vacío, no como lista (se mira el JSON crudo, no el decodificado).
        $this->assertStringContainsString('"properties":{}', (string) $respuesta->getContent());
    }

    public function test_unknown_tools_and_a_malformed_client_name_are_rejected(): void
    {
        $this->ejecutar('no_existe')->assertNotFound();
        $this->ejecutar('catalogo_dashboard', [], 'cliente <script>')->assertStatus(422)->assertExactJson(['error' => 'invalid']);
    }

    // --- Lectura ------------------------------------------------------------------------------------------------------------

    public function test_the_catalog_and_the_assistant_queries_work_without_a_session(): void
    {
        $catalogo = $this->ejecutar('catalogo_dashboard')->assertOk();
        $this->assertFalse($catalogo->json('error'));
        $this->assertStringContainsString('Catálogo de secciones', $catalogo->json('contenido'));
        $this->assertStringContainsString('Tulum', $catalogo->json('contenido'));

        $personal = $this->ejecutar('consultar_personal', ['operacion' => 'contar'])->assertOk();
        $this->assertFalse($personal->json('error'), (string) $personal->json('contenido'));
    }

    public function test_pending_attendance_lists_who_is_missing_without_personal_data(): void
    {
        $falta = $this->empleado(['nombre_completo' => 'Persona Pendiente', 'no_empleado' => 'EMP-1', 'puesto' => 'Operador', 'curp' => 'AAAA000101HQRXXX01']);
        $listo = $this->empleado(['no_empleado' => 'EMP-2']);
        RegistroDiario::factory()->create(['empleado_id' => $listo->id, 'estacion_id' => $this->estacion->id, 'fecha' => now('America/Cancun')->toDateString()]);
        $this->empleado(['estatus' => EmpleadoEstatus::Vacante->value, 'no_empleado' => 'VAC-1']);

        $detalle = $this->contenido($this->ejecutar('asistencia_pendiente_hoy', ['estacion' => 'tulum'])->assertOk());

        $this->assertSame(1, $detalle['sin_asistencia']);
        $this->assertSame([['no_empleado' => 'EMP-1', 'nombre' => 'Persona Pendiente', 'puesto' => 'Operador']], $detalle['personal']);
        $this->assertStringNotContainsString('AAAA000101', (string) json_encode($detalle));

        $resumen = $this->contenido($this->ejecutar('asistencia_pendiente_hoy')->assertOk());
        $this->assertSame(['estacion' => 'Tulum', 'personal_activo' => 2, 'con_asistencia' => 1, 'completa' => false], $resumen['estaciones'][0]);
    }

    // --- Captura ------------------------------------------------------------------------------------------------------------

    public function test_it_captures_todays_attendance_as_the_system_account_and_logs_it(): void
    {
        $a = $this->empleado(['no_empleado' => 'EMP-1']);
        $b = $this->empleado(['no_empleado' => 'EMP-2']);

        $resultado = $this->contenido($this->ejecutar('capturar_asistencia', [
            'estacion' => 'Tulum',
            'registros' => [
                ['no_empleado' => 'EMP-1', 'estatus' => 'presente'],
                ['no_empleado' => 'emp-2', 'estatus' => 'vacaciones', 'fecha_inicio' => '2030-03-10', 'fecha_fin' => '2030-03-20', 'notas' => "  Por   autorizar\n"],
            ],
        ])->assertOk());

        $this->assertSame(2, $resultado['capturados']);
        $this->assertSame(0, $resultado['pendientes_restantes']);
        $this->assertSame(now('America/Cancun')->toDateString(), $resultado['fecha']);

        $cuenta = User::query()->where('email', config('ecosistema.ia.cuenta.correo'))->firstOrFail();
        $this->assertFalse($cuenta->roles()->exists());
        $this->assertNull($cuenta->estacion_id);

        $registro = RegistroDiario::query()->where('empleado_id', $b->id)->firstOrFail();
        $this->assertSame('vacaciones', $registro->estatus->value);
        $this->assertSame($this->estacion->id, $registro->estacion_id);
        $this->assertSame($cuenta->id, $registro->registrado_por);
        $this->assertSame('Por autorizar', $registro->notas);
        $this->assertSame(2, RegistroDiario::query()->whereIn('empleado_id', [$a->id, $b->id])->count());

        $this->assertTrue(Activity::query()->where('log_name', 'registro_diario')->where('causer_id', $cuenta->id)->exists());
        $entrada = Activity::query()->where('log_name', 'asistencia_ia')->firstOrFail();
        $this->assertSame('asistente-escritura', $entrada->properties['cliente']);
        $this->assertSame(2, $entrada->properties['capturados']);
        $this->assertNull(Auth::user(), 'La cuenta de sistema no debe quedar autenticada después de la llamada.');
    }

    public function test_it_only_captures_today_in_cancun_even_when_utc_is_already_tomorrow(): void
    {
        // 02:00 UTC del 11 = 21:00 del 10 en Cancún.
        Carbon::setTestNow(Carbon::parse('2030-03-11 02:00:00', 'UTC'));
        $this->empleado(['no_empleado' => 'EMP-1']);

        $resultado = $this->contenido($this->ejecutar('capturar_asistencia', ['estacion' => 'Tulum', 'registros' => [['no_empleado' => 'EMP-1', 'estatus' => 'presente']]])->assertOk());

        $this->assertSame('2030-03-10', $resultado['fecha']);
        $this->assertSame('2030-03-10', RegistroDiario::query()->firstOrFail()->fecha->toDateString());
    }

    public function test_it_never_overwrites_an_existing_record_and_the_ai_cannot_ask_to(): void
    {
        $empleado = $this->empleado(['no_empleado' => 'EMP-1']);
        RegistroDiario::factory()->create(['empleado_id' => $empleado->id, 'estacion_id' => $this->estacion->id, 'fecha' => now('America/Cancun')->toDateString(), 'estatus' => 'descanso', 'notas' => 'capturado por una persona']);
        $entrada = ['estacion' => 'Tulum', 'registros' => [['no_empleado' => 'EMP-1', 'estatus' => 'presente']]];

        $resultado = $this->contenido($this->ejecutar('capturar_asistencia', $entrada)->assertOk());

        $this->assertSame(0, $resultado['capturados']);
        $this->assertSame([['no_empleado' => 'EMP-1', 'estatus_actual' => 'Descanso']], $resultado['omitidos_ya_capturados']);

        // Pedir sobrescribir no es una opción: la llamada completa se rechaza y nada cambia.
        $pedido = $this->ejecutar('capturar_asistencia', $entrada + ['sobrescribir' => true])->assertOk();
        $this->assertTrue($pedido->json('error'));
        $this->assertStringContainsString('sobrescribir', $pedido->json('contenido'));

        $registro = RegistroDiario::query()->firstOrFail();
        $this->assertSame('descanso', $registro->estatus->value);
        $this->assertSame('capturado por una persona', $registro->notas);
        $this->assertSame(1, RegistroDiario::query()->count());
    }

    public function test_a_capture_made_by_a_person_after_the_roster_was_read_is_not_overwritten(): void
    {
        // Simula la carrera: el registro aparece entre la lectura del roster y el guardado.
        $empleado = $this->empleado(['no_empleado' => 'EMP-1']);
        $otro = $this->empleado(['no_empleado' => 'EMP-2']);
        $cuenta = app(CuentaIa::class)->usuario();
        $hoy = CarbonImmutable::parse(now('America/Cancun')->toDateString());
        RegistroDiario::factory()->create(['empleado_id' => $empleado->id, 'estacion_id' => $this->estacion->id, 'fecha' => $hoy->toDateString(), 'estatus' => 'vacaciones']);

        $resultado = app(AsistenciaCapturaService::class)->crearFaltantes($this->estacion, $hoy, [
            ['empleado_id' => $empleado->id, 'estatus' => 'presente'],
            ['empleado_id' => $otro->id, 'estatus' => 'presente'],
        ], $cuenta);

        $this->assertSame([$otro->id], $resultado['creados']);
        $this->assertSame([$empleado->id], $resultado['existentes']);
        $this->assertSame('vacaciones', RegistroDiario::query()->where('empleado_id', $empleado->id)->firstOrFail()->estatus->value);
    }

    public function test_values_of_the_wrong_type_reject_only_their_row_and_never_crash_the_call(): void
    {
        $this->empleado(['no_empleado' => '1001']);
        $this->empleado(['no_empleado' => 'EMP-2']);

        $resultado = $this->contenido($this->ejecutar('capturar_asistencia', [
            'estacion' => 'Tulum',
            'registros' => [
                ['no_empleado' => ['EMP-2'], 'estatus' => 'presente'],
                ['no_empleado' => null, 'estatus' => 'presente'],
                ['no_empleado' => 1001, 'estatus' => 'presente'],
                ['no_empleado' => 'EMP-2', 'estatus' => ['presente']],
                ['no_empleado' => 'EMP-2', 'estatus' => 'presente', 'notas' => ['x']],
            ],
        ])->assertOk());

        $this->assertSame(1, $resultado['capturados']);
        $this->assertSame([1, 2, 4, 5], array_column($resultado['rechazados'], 'fila'));
        $this->assertSame('no_empleado debe ser texto o número.', $resultado['rechazados'][0]['motivo']);

        $this->assertTrue($this->ejecutar('capturar_asistencia', ['estacion' => ['Tulum'], 'registros' => [['no_empleado' => '1001', 'estatus' => 'presente']]])->json('error'));
        $this->assertTrue($this->ejecutar('asistencia_pendiente_hoy', ['estacion' => ['Tulum']])->json('error'));
    }

    public function test_notes_are_cleaned_and_empty_dates_are_stored_as_null(): void
    {
        $this->empleado(['no_empleado' => 'EMP-1']);

        $this->ejecutar('capturar_asistencia', ['estacion' => 'Tulum', 'registros' => [[
            'no_empleado' => 'EMP-1', 'estatus' => 'presente', 'fecha_inicio' => '', 'fecha_fin' => '', 'notas' => "Linea 1\u{0007}\u{200B}\n\n  Linea   2 ".str_repeat('x', 2000),
        ]]])->assertOk();

        $registro = RegistroDiario::query()->firstOrFail();
        $this->assertNull($registro->fecha_inicio);
        $this->assertNull($registro->fecha_fin);
        $this->assertSame(1000, mb_strlen($registro->notas));
        $this->assertStringStartsWith('Linea 1 Linea 2 xxx', $registro->notas);
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\x{200B}]/u', $registro->notas);
    }

    public function test_the_system_account_is_created_only_once(): void
    {
        $primera = app(CuentaIa::class)->usuario();
        $segunda = (new CuentaIa)->usuario();

        $this->assertSame($primera->id, $segunda->id);
        $this->assertSame(1, User::query()->where('email', config('ecosistema.ia.cuenta.correo'))->count());
    }

    public function test_invalid_rows_are_rejected_one_by_one_and_the_valid_ones_are_saved(): void
    {
        $bueno = $this->empleado(['no_empleado' => 'EMP-1']);
        $this->empleado(['no_empleado' => 'VAC-1', 'estatus' => EmpleadoEstatus::Vacante->value]);
        $otraEstacion = Estacion::factory()->create(['is_operativa' => true]);
        Empleado::factory()->create(['estacion_id' => $otraEstacion->id, 'estatus' => EmpleadoEstatus::Activo->value, 'no_empleado' => 'AJENO-1']);
        $this->empleado(['no_empleado' => 'EMP-5']);
        $this->empleado(['no_empleado' => 'EMP-6']);

        $resultado = $this->contenido($this->ejecutar('capturar_asistencia', [
            'estacion' => 'Tulum',
            'registros' => [
                ['no_empleado' => 'EMP-1', 'estatus' => 'presente'],
                ['no_empleado' => 'EMP-1', 'estatus' => 'descanso'],
                ['no_empleado' => 'VAC-1', 'estatus' => 'presente'],
                ['no_empleado' => 'AJENO-1', 'estatus' => 'presente'],
                ['no_empleado' => 'NO-EXISTE', 'estatus' => 'presente'],
                ['no_empleado' => 'EMP-5', 'estatus' => 'inventado'],
                ['no_empleado' => 'EMP-6', 'estatus' => 'vacaciones', 'fecha_inicio' => '2030-13-45'],
                ['no_empleado' => 'EMP-6', 'estatus' => 'vacaciones', 'fecha_inicio' => '2030-03-20', 'fecha_fin' => '2030-03-10'],
                ['estatus' => 'presente'],
                'texto suelto',
            ],
        ])->assertOk());

        $this->assertSame(1, $resultado['capturados']);
        $this->assertCount(9, $resultado['rechazados']);
        $this->assertSame([2, 3, 4, 5, 6, 7, 8, 9, 10], array_column($resultado['rechazados'], 'fila'));
        $this->assertSame(1, RegistroDiario::query()->count());
        $this->assertSame($bueno->id, RegistroDiario::query()->firstOrFail()->empleado_id);
    }

    public function test_request_level_errors_stop_everything(): void
    {
        $this->empleado(['no_empleado' => 'EMP-1']);
        Estacion::factory()->create(['nombre' => 'Edificio Zonal Este', 'codigo' => '00', 'is_operativa' => false]);
        $fila = ['no_empleado' => 'EMP-1', 'estatus' => 'presente'];

        foreach ([
            ['estacion' => 'No Existe', 'registros' => [$fila]],
            ['estacion' => 'Edificio Zonal Este', 'registros' => [$fila]],
            ['estacion' => '', 'registros' => [$fila]],
            ['estacion' => 'Tulum', 'registros' => []],
            ['estacion' => 'Tulum', 'registros' => 'EMP-1'],
            ['estacion' => 'Tulum', 'registros' => ['a' => $fila]],
            ['estacion' => 'Tulum', 'registros' => [$fila], 'sobrescribir' => true],
        ] as $entrada) {
            $respuesta = $this->ejecutar('capturar_asistencia', $entrada)->assertOk();
            $this->assertTrue($respuesta->json('error'), (string) json_encode($entrada));
        }

        config(['ecosistema.ia.max_registros' => 1]);
        $this->assertTrue($this->ejecutar('capturar_asistencia', ['estacion' => 'Tulum', 'registros' => [$fila, $fila]])->json('error'));

        $this->assertSame(0, RegistroDiario::query()->count());
    }

    public function test_extra_fields_cannot_change_who_or_where_it_is_recorded(): void
    {
        $empleado = $this->empleado(['no_empleado' => 'EMP-1']);
        $otraEstacion = Estacion::factory()->create(['is_operativa' => true]);

        $this->ejecutar('capturar_asistencia', ['estacion' => 'Tulum', 'registros' => [[
            'no_empleado' => 'EMP-1', 'estatus' => 'presente', 'estacion_id' => $otraEstacion->id, 'registrado_por' => 999, 'fecha' => '2020-01-01', 'empleado_id' => 12345,
        ]]]);

        $registro = RegistroDiario::query()->firstOrFail();
        $this->assertSame($empleado->id, $registro->empleado_id);
        $this->assertSame($this->estacion->id, $registro->estacion_id);
        $this->assertNotSame(999, $registro->registrado_por);
        $this->assertSame(now('America/Cancun')->toDateString(), $registro->fecha->toDateString());
    }

    public function test_a_tampered_system_account_is_not_used(): void
    {
        $this->empleado(['no_empleado' => 'EMP-1']);
        $impostor = User::factory()->create(['email' => config('ecosistema.ia.cuenta.correo')]);
        $this->seed(RoleSeeder::class);
        $impostor->assignRole('Administrador');

        $respuesta = $this->ejecutar('capturar_asistencia', ['estacion' => 'Tulum', 'registros' => [['no_empleado' => 'EMP-1', 'estatus' => 'presente']]])->assertOk();

        $this->assertTrue($respuesta->json('error'));
        $this->assertSame(0, RegistroDiario::query()->count());
    }
}
