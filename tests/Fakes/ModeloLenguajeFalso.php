<?php

namespace Tests\Fakes;

use Anthropic\Beta\Messages\BetaMessage;
use App\Services\Asistente\ModeloLenguaje;

/**
 * Sustituto de la Claude API en pruebas: devuelve respuestas encoladas
 * (en el formato de la API) y guarda los parámetros de cada petición.
 */
final class ModeloLenguajeFalso implements ModeloLenguaje
{
    /** @var list<array<string, mixed>> */
    public array $peticiones = [];

    /** @var list<array<string, mixed>> */
    private array $respuestas = [];

    public function __construct(private readonly bool $disponible = true) {}

    /**
     * @param  list<array<string, mixed>>  $contenido  bloques en formato de la API
     */
    public function encolar(array $contenido, string $motivo = 'end_turn'): self
    {
        $this->respuestas[] = [
            'id' => 'msg_'.count($this->respuestas),
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-5-5',
            'content' => $contenido,
            'stop_reason' => $motivo,
            'stop_sequence' => null,
            'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
        ];

        return $this;
    }

    public function encolarTexto(string $texto, string $motivo = 'end_turn'): self
    {
        return $this->encolar([['type' => 'text', 'text' => $texto]], $motivo);
    }

    public function disponible(): bool
    {
        return $this->disponible;
    }

    public function transmitir(array $parametros, ?callable $alRecibirTexto = null): BetaMessage
    {
        $this->peticiones[] = $parametros;
        $respuesta = array_shift($this->respuestas) ?? throw new \LogicException('No hay respuestas encoladas en ModeloLenguajeFalso.');

        if ($alRecibirTexto !== null) {
            foreach ($respuesta['content'] as $bloque) {
                if ($bloque['type'] === 'text') {
                    $alRecibirTexto($bloque['text']);
                }
            }
        }

        return BetaMessage::fromArray($respuesta);
    }
}
