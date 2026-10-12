<?php

namespace Tests\Feature\Asistente;

use App\Models\User;
use App\Services\Asistente\AsistenteService;
use App\Services\Asistente\ClienteClaude;
use App\Services\Asistente\ModeloLenguaje;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cliente real (SDK oficial de Anthropic) contra un transporte HTTP
 * simulado: verifica el cuerpo que se enviaría a la API y que el streaming
 * se acumula en el mensaje final. Nunca toca la red.
 */
class ClienteClaudeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<array<string, mixed>>  $peticiones
     */
    private function cliente(string $sse, array &$peticiones): ClienteClaude
    {
        config(['services.anthropic.key' => 'sk-ant-prueba', 'services.anthropic.model' => 'claude-sonnet-5-5']);

        $pila = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'text/event-stream'], $sse),
        ]));
        $pila->push(Middleware::history($peticiones));

        return new ClienteClaude(['transporter' => new Guzzle(['handler' => $pila]), 'maxRetries' => 0]);
    }

    private function evento(string $tipo, array $datos): string
    {
        return "event: {$tipo}\ndata: ".json_encode(['type' => $tipo, ...$datos])."\n\n";
    }

    public function test_streams_text_and_returns_the_accumulated_message(): void
    {
        $sse = $this->evento('message_start', ['message' => [
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5',
            'content' => [], 'stop_reason' => null, 'stop_sequence' => null, 'usage' => ['input_tokens' => 12, 'output_tokens' => 1],
        ]])
            .$this->evento('content_block_start', ['index' => 0, 'content_block' => ['type' => 'text', 'text' => '']])
            .$this->evento('content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Hay 2 ']])
            .$this->evento('content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'personas.']])
            .$this->evento('content_block_stop', ['index' => 0])
            .$this->evento('message_delta', ['delta' => ['stop_reason' => 'end_turn', 'stop_sequence' => null], 'usage' => ['output_tokens' => 7]])
            .$this->evento('message_stop', []);

        $peticiones = [];
        $fragmentos = [];

        $mensaje = $this->cliente($sse, $peticiones)->transmitir([
            'maxTokens' => 1000,
            'system' => 'Eres el asistente.',
            'messages' => [['role' => 'user', 'content' => '¿Cuántos?']],
            'outputConfig' => ['effort' => 'low'],
        ], function (string $texto) use (&$fragmentos): void {
            $fragmentos[] = $texto;
        });

        $this->assertSame(['Hay 2 ', 'personas.'], $fragmentos);
        $this->assertSame('end_turn', $mensaje->stopReason);
        $this->assertSame('Hay 2 personas.', $mensaje->content[0]->text);

        $cuerpo = json_decode((string) $peticiones[0]['request']->getBody(), true);
        $this->assertSame('claude-sonnet-5-5', $cuerpo['model']);
        $this->assertTrue($cuerpo['stream']);
        $this->assertSame('default', $cuerpo['fallbacks']);
        $this->assertSame(['effort' => 'low'], $cuerpo['output_config']);
        $this->assertSame(1000, $cuerpo['max_tokens']);
        $this->assertStringContainsString('server-side-fallback-2026-07-01', $peticiones[0]['request']->getHeaderLine('anthropic-beta'));
        $this->assertSame('sk-ant-prueba', $peticiones[0]['request']->getHeaderLine('x-api-key'));
    }

    public function test_full_tool_loop_serializes_tool_use_and_tool_result_for_the_api(): void
    {
        $inicio = fn (string $id) => $this->evento('message_start', ['message' => [
            'id' => $id, 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5',
            'content' => [], 'stop_reason' => null, 'stop_sequence' => null, 'usage' => ['input_tokens' => 5, 'output_tokens' => 1],
        ]]);
        $entrada = json_encode(['estacion' => null, 'estatus' => 'activo', 'tipo_plaza' => null, 'puesto_contiene' => null, 'nombre_contiene' => null, 'operacion' => 'contar']);

        $usoHerramienta = $inicio('msg_1')
            .$this->evento('content_block_start', ['index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_9', 'name' => 'consultar_personal', 'input' => (object) []]])
            .$this->evento('content_block_delta', ['index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => $entrada]])
            .$this->evento('content_block_stop', ['index' => 0])
            .$this->evento('message_delta', ['delta' => ['stop_reason' => 'tool_use', 'stop_sequence' => null], 'usage' => ['output_tokens' => 20]])
            .$this->evento('message_stop', []);
        $respuestaFinal = $inicio('msg_2')
            .$this->evento('content_block_start', ['index' => 0, 'content_block' => ['type' => 'text', 'text' => '']])
            .$this->evento('content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'No hay personal activo.']])
            .$this->evento('content_block_stop', ['index' => 0])
            .$this->evento('message_delta', ['delta' => ['stop_reason' => 'end_turn', 'stop_sequence' => null], 'usage' => ['output_tokens' => 6]])
            .$this->evento('message_stop', []);

        config(['services.anthropic.key' => 'sk-ant-prueba']);
        $peticiones = [];
        $pila = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'text/event-stream'], $usoHerramienta),
            new Response(200, ['Content-Type' => 'text/event-stream'], $respuestaFinal),
        ]));
        $pila->push(Middleware::history($peticiones));
        $this->app->instance(ModeloLenguaje::class, new ClienteClaude(['transporter' => new Guzzle(['handler' => $pila]), 'maxRetries' => 0]));

        $eventos = [];
        app(AsistenteService::class)->responder('¿Cuántos activos?', [], User::factory()->create(), function (string $tipo, mixed $contenido) use (&$eventos): void {
            $eventos[] = [$tipo, $contenido];
        });

        $segunda = json_decode((string) $peticiones[1]['request']->getBody(), true);
        $this->assertSame('tool_use', $segunda['messages'][1]['content'][0]['type']);
        $this->assertSame('toolu_9', $segunda['messages'][1]['content'][0]['id']);
        $this->assertSame('activo', $segunda['messages'][1]['content'][0]['input']['estatus']);
        $this->assertSame('tool_result', $segunda['messages'][2]['content'][0]['type']);
        $this->assertSame('toolu_9', $segunda['messages'][2]['content'][0]['tool_use_id']);
        $this->assertSame(0, json_decode($segunda['messages'][2]['content'][0]['content'], true)['total']);
        $this->assertTrue($segunda['tools'][0]['strict']);
        $this->assertArrayHasKey('input_schema', $segunda['tools'][0]);
        $this->assertContains(['texto', 'No hay personal activo.'], $eventos);
    }

    public function test_is_unavailable_without_a_key(): void
    {
        config(['services.anthropic.key' => '']);

        $this->assertFalse((new ClienteClaude)->disponible());
    }
}
