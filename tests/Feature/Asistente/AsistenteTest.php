<?php

namespace Tests\Feature\Asistente;

use App\Enums\EstatusAsistencia;
use App\Enums\TipoColumna;
use App\Models\Empleado;
use App\Models\Estacion;
use App\Models\RegistroDiario;
use App\Services\Asistente\HerramientasAsistente;
use App\Services\Asistente\ModeloLenguaje;
use App\Support\DatosSensibles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithSecciones;
use Tests\Fakes\ModeloLenguajeFalso;
use Tests\TestCase;

/**
 * Asistente de preguntas del dashboard. La Claude API se sustituye por
 * ModeloLenguajeFalso: se prueba el ciclo de herramientas, el streaming,
 * la autorización y que solo salgan resultados acotados.
 */
class AsistenteTest extends TestCase
{
    use InteractsWithSecciones, RefreshDatabase;

    private ModeloLenguajeFalso $modelo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->modelo = new ModeloLenguajeFalso;
        $this->app->instance(ModeloLenguaje::class, $this->modelo);
    }

    /**
     * @return list<array{tipo: string, contenido: mixed}>
     */
    private function eventos(string $cuerpo): array
    {
        return array_map(fn (string $linea) => json_decode($linea, true), array_values(array_filter(explode("\n", $cuerpo))));
    }

    /**
     * @param  array<string, mixed>  $entrada
     */
    private function usoDeHerramienta(string $nombre, array $entrada): array
    {
        return [['type' => 'tool_use', 'id' => 'toolu_01', 'name' => $nombre, 'input' => $entrada]];
    }

    private function herramientas(): HerramientasAsistente
    {
        return app(HerramientasAsistente::class);
    }

    public function test_answers_active_staff_per_station_using_the_personal_tool(): void
    {
        $usuario = $this->comoAdministrador();
        $chetumal = Estacion::factory()->create(['nombre' => 'Chetumal']);
        Empleado::factory()->count(2)->create(['estacion_id' => $chetumal->id]);
        Empleado::factory()->vacante()->create(['estacion_id' => $chetumal->id]);
        Empleado::factory()->create(['estacion_id' => Estacion::factory()->create(['nombre' => 'Tulum'])->id]);

        $this->modelo
            ->encolar($this->usoDeHerramienta('consultar_personal', [
                'estacion' => 'chetumal', 'estatus' => 'activo', 'tipo_plaza' => null,
                'puesto_contiene' => null, 'nombre_contiene' => null, 'operacion' => 'contar',
            ]), 'tool_use')
            ->encolarTexto('Tienes **2** personas activas en Chetumal.');

        $respuesta = $this->postJson(route('dashboard.asistente'), ['pregunta' => '¿Cuánto personal activo tengo en Chetumal?']);

        $respuesta->assertOk();
        $this->assertStringStartsWith('application/x-ndjson', $respuesta->headers->get('Content-Type'));
        $eventos = $this->eventos($respuesta->streamedContent());

        $this->assertContains(['tipo' => 'estado', 'contenido' => 'Consultando Personal…'], $eventos);
        $this->assertContains(['tipo' => 'texto', 'contenido' => 'Tienes **2** personas activas en Chetumal.'], $eventos);
        $this->assertContains(['tipo' => 'fuentes', 'contenido' => [['titulo' => 'Personal', 'url' => route('agenda.personal.index')]]], $eventos);
        $this->assertSame(['tipo' => 'fin', 'contenido' => null], end($eventos));

        $resultado = $this->modelo->peticiones[1]['messages'][2]['content'][0];
        $this->assertSame('tool_result', $resultado['type']);
        $this->assertSame('toolu_01', $resultado['toolUseID']);
        $this->assertSame(2, json_decode($resultado['content'], true)['total']);

        $this->assertTrue(Activity::query()->where('event', 'asistente_pregunta')->where('causer_id', $usuario->id)->exists());
    }

    public function test_the_catalog_of_sections_is_sent_as_context(): void
    {
        $this->comoAdministrador();
        $seccion = $this->seccionConColumnas(['Ubicación' => TipoColumna::Texto]);
        $this->modelo->encolarTexto('Listo.');

        $this->postJson(route('dashboard.asistente'), ['pregunta' => 'Hola'])->streamedContent();

        $contexto = $this->modelo->peticiones[0]['system'][1]['text'];
        $this->assertStringContainsString($seccion->slug, $contexto);
        $this->assertStringContainsString('Ubicación [texto]', $contexto);
        $this->assertSame('low', $this->modelo->peticiones[0]['outputConfig']['effort']);
    }

    public function test_follow_up_questions_send_the_previous_turns(): void
    {
        $this->comoAdministrador();
        $this->modelo->encolarTexto('En Tulum hay 1.');

        $this->postJson(route('dashboard.asistente'), [
            'pregunta' => '¿Y en Tulum?',
            'historial' => [['pregunta' => '¿Cuántos en Chetumal?', 'respuesta' => 'Hay 2.']],
        ])->streamedContent();

        $mensajes = $this->modelo->peticiones[0]['messages'];
        $this->assertSame(['role' => 'user', 'content' => '¿Cuántos en Chetumal?'], $mensajes[0]);
        $this->assertSame(['role' => 'assistant', 'content' => 'Hay 2.'], $mensajes[1]);
    }

    public function test_a_refusal_is_reported_to_the_user(): void
    {
        $this->comoAdministrador();
        $this->modelo->encolarTexto('', 'refusal');

        $eventos = $this->eventos($this->postJson(route('dashboard.asistente'), ['pregunta' => 'Algo'])->streamedContent());

        $this->assertSame('error', collect($eventos)->firstWhere('tipo', 'error')['tipo']);
    }

    public function test_returns_503_when_the_api_key_is_missing(): void
    {
        $this->app->instance(ModeloLenguaje::class, new ModeloLenguajeFalso(disponible: false));
        $this->comoAdministrador();

        $this->postJson(route('dashboard.asistente'), ['pregunta' => '¿Cuántos?'])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'ia_no_configurada');
        $this->get(route('dashboard'))->assertDontSee('Pregunta rápida');
    }

    public function test_dashboard_shows_the_assistant_when_configured(): void
    {
        $this->comoAdministrador();

        $this->get(route('dashboard'))->assertOk()->assertSee('Pregunta rápida');
    }

    public function test_question_is_validated(): void
    {
        $this->comoAdministrador();

        $this->postJson(route('dashboard.asistente'), ['pregunta' => ''])->assertJsonValidationErrors('pregunta');
        $this->postJson(route('dashboard.asistente'), ['pregunta' => str_repeat('a', 1001)])->assertJsonValidationErrors('pregunta');
        $this->postJson(route('dashboard.asistente'), ['pregunta' => 'Hola', 'historial' => array_fill(0, 5, ['pregunta' => 'a', 'respuesta' => 'b'])])
            ->assertJsonValidationErrors('historial');
    }

    public function test_only_zone_roles_can_ask(): void
    {
        $this->postJson(route('dashboard.asistente'), ['pregunta' => 'Hola'])->assertUnauthorized();

        $this->actingAsTwoFactorVerified($this->usuarioConRol('Estación'));
        $this->postJson(route('dashboard.asistente'), ['pregunta' => 'Hola'])->assertForbidden();
        $this->assertSame([], $this->modelo->peticiones);
    }

    public function test_section_tool_filters_and_aggregates_without_accents_or_case(): void
    {
        $seccion = $this->seccionConColumnas(['Estación' => TipoColumna::Texto, 'Monto' => TipoColumna::Numero, 'Estatus' => TipoColumna::Texto]);
        $this->filaEn($seccion, ['estacion' => 'Chetumal', 'monto' => 100, 'estatus' => 'Pagado']);
        $this->filaEn($seccion, ['estacion' => 'CHETUMAL', 'monto' => 50, 'estatus' => 'Pendiente']);
        $this->filaEn($seccion, ['estacion' => 'Tulum', 'monto' => 70, 'estatus' => 'Pagado']);

        $consulta = fn (array $extra) => json_decode($this->herramientas()->ejecutar('consultar_seccion', [
            'seccion' => $seccion->slug,
            'filtros' => [['columna' => 'estación', 'operador' => 'igual', 'valor' => 'chetumal']],
            'operacion' => 'contar', 'columna_objetivo' => null, 'limite' => null, ...$extra,
        ])['contenido'], true);

        $this->assertSame(2, $consulta([])['resultado']);
        $this->assertEquals(150, $consulta(['operacion' => 'sumar', 'columna_objetivo' => 'Monto'])['resultado']);
        $this->assertSame(['Pagado' => 1, 'Pendiente' => 1], $consulta(['operacion' => 'contar_por_valor', 'columna_objetivo' => 'Estatus'])['resultado']);
        $this->assertSame(0, $consulta(['filtros' => [['columna' => 'Monto', 'operador' => 'mayor', 'valor' => '1000']]])['resultado']);
        $this->assertSame(1, $consulta(['filtros' => [['columna' => 'Monto', 'operador' => 'menor', 'valor' => '60']]])['resultado']);
    }

    public function test_sensitive_columns_never_reach_the_assistant(): void
    {
        $seccion = $this->seccionConColumnas(['Nombre' => TipoColumna::Texto, 'CURP' => TipoColumna::Texto, 'Teléfono celular' => TipoColumna::Texto]);
        $this->filaEn($seccion, ['nombre' => 'Ana', 'curp' => 'LOPA800101MQRPZN09', 'telefono_celular' => '9831234567']);

        $listado = $this->herramientas()->ejecutar('consultar_seccion', [
            'seccion' => $seccion->slug, 'filtros' => [], 'operacion' => 'listar', 'columna_objetivo' => null, 'limite' => 10,
        ]);
        $porCurp = $this->herramientas()->ejecutar('consultar_seccion', [
            'seccion' => $seccion->slug, 'filtros' => [['columna' => 'CURP', 'operador' => 'contiene', 'valor' => 'LOPA']], 'operacion' => 'contar',
            'columna_objetivo' => null, 'limite' => null,
        ]);

        $this->assertStringNotContainsString('LOPA800101', $listado['contenido']);
        $this->assertStringNotContainsString('9831234567', $listado['contenido']);
        $this->assertStringContainsString('Ana', $listado['contenido']);
        $this->assertTrue($porCurp['error']);
    }

    public function test_personal_data_patterns_are_redacted_from_any_value(): void
    {
        $texto = DatosSensibles::redactar('CURP LOPA800101MQRPZN09, RFC LOPA800101AB1, NSS 12345678901, tel. 983 123 4567, correo ana@example.com, folio A-12');

        $this->assertStringNotContainsString('LOPA800101', $texto);
        $this->assertStringNotContainsString('12345678901', $texto);
        $this->assertStringNotContainsString('123 4567', $texto);
        $this->assertStringNotContainsString('ana@example.com', $texto);
        $this->assertStringContainsString('folio A-12', $texto);
        $this->assertTrue(DatosSensibles::esColumnaSensible('Fecha de nacimiento'));
        $this->assertFalse(DatosSensibles::esColumnaSensible('Estación'));
    }

    public function test_counting_by_value_keeps_zero_and_numbers_compare_as_numbers(): void
    {
        $seccion = $this->seccionConColumnas(['Fallas' => TipoColumna::Numero]);
        $this->filaEn($seccion, ['fallas' => 0]);
        $this->filaEn($seccion, ['fallas' => 5.0]);
        $this->filaEn($seccion, ['fallas' => null]);

        $porValor = json_decode($this->herramientas()->ejecutar('consultar_seccion', [
            'seccion' => $seccion->slug, 'filtros' => [], 'operacion' => 'contar_por_valor', 'columna_objetivo' => 'Fallas', 'limite' => null,
        ])['contenido'], true);
        $cinco = json_decode($this->herramientas()->ejecutar('consultar_seccion', [
            'seccion' => $seccion->slug, 'filtros' => [['columna' => 'Fallas', 'operador' => 'igual', 'valor' => '5']], 'operacion' => 'contar',
            'columna_objetivo' => null, 'limite' => null,
        ])['contenido'], true);

        $this->assertSame(1, $porValor['resultado']['0']);
        $this->assertSame(1, $porValor['resultado']['(vacío)']);
        $this->assertSame(1, $cinco['resultado']);
    }

    public function test_question_is_logged_even_when_the_api_fails(): void
    {
        $this->comoAdministrador();

        $this->postJson(route('dashboard.asistente'), ['pregunta' => '¿Cuántos?'])->streamedContent();

        $this->assertSame('error', Activity::query()->where('event', 'asistente_pregunta')->sole()->properties['estado']);
    }

    public function test_section_tool_reports_unknown_columns_as_errors(): void
    {
        $seccion = $this->seccionConColumnas(['Folio' => TipoColumna::Texto]);

        $resultado = $this->herramientas()->ejecutar('consultar_seccion', [
            'seccion' => $seccion->slug,
            'filtros' => [['columna' => 'Inventada', 'operador' => 'igual', 'valor' => 'x']],
            'operacion' => 'contar', 'columna_objetivo' => null, 'limite' => null,
        ]);

        $this->assertTrue($resultado['error']);
        $this->assertTrue($this->herramientas()->ejecutar('consultar_seccion', ['seccion' => 'no-existe', 'filtros' => [], 'operacion' => 'contar'])['error']);
    }

    public function test_listing_personal_never_includes_sensitive_fields(): void
    {
        Empleado::factory()->create(['curp' => 'CURPSECRETA0000000', 'nss' => '99999999999']);

        $contenido = $this->herramientas()->ejecutar('consultar_personal', [
            'estacion' => null, 'estatus' => null, 'tipo_plaza' => null,
            'puesto_contiene' => null, 'nombre_contiene' => null, 'operacion' => 'listar',
        ])['contenido'];

        $this->assertStringNotContainsString('CURPSECRETA', $contenido);
        $this->assertStringNotContainsString('99999999999', $contenido);
        $this->assertSame(1, json_decode($contenido, true)['total']);
    }

    public function test_attendance_tool_summarizes_the_day_by_station(): void
    {
        $chetumal = Estacion::factory()->create(['nombre' => 'Chetumal', 'is_operativa' => true]);
        $tulum = Estacion::factory()->create(['nombre' => 'Tulum', 'is_operativa' => true]);
        RegistroDiario::factory()->count(2)->create(['estacion_id' => $chetumal->id, 'fecha' => '2026-10-12']);
        RegistroDiario::factory()->create(['estacion_id' => $chetumal->id, 'fecha' => '2026-10-12', 'estatus' => EstatusAsistencia::Vacaciones->value]);

        $resultado = json_decode($this->herramientas()->ejecutar('consultar_asistencia', ['fecha' => '2026-10-12', 'estacion' => null])['contenido'], true);

        $this->assertSame(2, $resultado['por_estacion']['Chetumal'][EstatusAsistencia::Presente->label()]);
        $this->assertSame(1, $resultado['por_estacion']['Chetumal'][EstatusAsistencia::Vacaciones->label()]);
        $this->assertContains($tulum->nombre, $resultado['estaciones_sin_captura']);
    }
}
