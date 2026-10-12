<?php

namespace App\Services\Asistente;

use Anthropic\Beta\Messages\BetaMessage;

/**
 * Puerta única hacia la Claude API. Las pruebas la sustituyen por una
 * implementación falsa; nunca se llama a la API real desde la suite.
 */
interface ModeloLenguaje
{
    public function disponible(): bool;

    /**
     * Petición con streaming. $alRecibirTexto recibe cada fragmento de
     * texto conforme llega; devuelve el mensaje completo ya acumulado.
     *
     * @param  array<string, mixed>  $parametros  argumentos con nombre de messages->createStream (sin model/betas)
     * @param  null|callable(string): void  $alRecibirTexto
     */
    public function transmitir(array $parametros, ?callable $alRecibirTexto = null): BetaMessage;
}
