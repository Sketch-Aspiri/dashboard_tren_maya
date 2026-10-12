<?php

namespace App\Services\Asistente;

use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Beta\Messages\BetaRawContentBlockDeltaEvent;
use Anthropic\Beta\Messages\BetaTextDelta;
use Anthropic\Client;
use Anthropic\Lib\Streaming\MessageAccumulator;

/**
 * Implementación real de ModeloLenguaje con el SDK oficial de Anthropic
 * para PHP. Se usa el espacio "beta" para activar el respaldo del lado del
 * servidor ante un rechazo por clasificador de seguridad
 * (fallbacks: "default"); si aun así el modelo rechaza, el mensaje llega
 * con stop_reason "refusal" y quien llama lo maneja.
 */
final class ClienteClaude implements ModeloLenguaje
{
    private const BETAS = ['server-side-fallback-2026-07-01'];

    private ?Client $cliente = null;

    /**
     * @param  array<string, mixed>  $opcionesHttp  opciones extra del SDK (p. ej. 'transporter' en pruebas)
     */
    public function __construct(private readonly array $opcionesHttp = []) {}

    public function disponible(): bool
    {
        return trim((string) config('services.anthropic.key')) !== '';
    }

    public function modelo(): string
    {
        return (string) config('services.anthropic.model');
    }

    public function transmitir(array $parametros, ?callable $alRecibirTexto = null): BetaMessage
    {
        $flujo = $this->cliente()->beta->messages->createStream(
            ...$parametros,
            model: $this->modelo(),
            fallbacks: 'default',
            betas: self::BETAS,
        );

        $acumulador = MessageAccumulator::forBetaMessages();

        foreach ($flujo as $evento) {
            $acumulador->accumulate($evento);

            if ($alRecibirTexto !== null
                && $evento instanceof BetaRawContentBlockDeltaEvent
                && $evento->delta instanceof BetaTextDelta) {
                $alRecibirTexto($evento->delta->text);
            }
        }

        /** @var BetaMessage */
        return $acumulador->message();
    }

    private function cliente(): Client
    {
        return $this->cliente ??= new Client(
            apiKey: (string) config('services.anthropic.key'),
            requestOptions: ['timeout' => (float) config('services.anthropic.timeout'), ...$this->opcionesHttp],
        );
    }
}
