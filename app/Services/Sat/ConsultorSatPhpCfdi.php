<?php

namespace App\Services\Sat;

use DOMDocument;
use PhpCfdi\CfdiExpresiones\DiscoverExtractor;
use PhpCfdi\SatEstadoCfdi\Consumer;
use PhpCfdi\SatEstadoCfdi\Soap\SoapConsumerClient;
use Throwable;

/**
 * Consulta real al SAT con phpcfdi/sat-estado-cfdi (SOAP, requiere ext-soap) y
 * arma la expresión impresa con phpcfdi/cfdi-expresiones. Solo CFDI regulares.
 */
final class ConsultorSatPhpCfdi implements ConsultorSat
{
    public function __construct(private readonly int $timeoutSegundos = 12) {}

    public function consultar(string $xml): ResultadoSat
    {
        if (! extension_loaded('soap')) {
            throw new ConsultaSatException('La extensión SOAP de PHP no está instalada.');
        }

        try {
            $documento = new DOMDocument;
            $documento->loadXML($xml, LIBXML_NONET);
            $expresion = (new DiscoverExtractor)->extract($documento);
        } catch (Throwable $e) {
            throw new ConsultaSatException('No se pudo armar la consulta con este CFDI: '.$e->getMessage(), 0, $e);
        }

        $limite = ini_set('default_socket_timeout', (string) $this->timeoutSegundos);

        try {
            $estado = (new Consumer(new SoapConsumerClient))->execute($expresion);
        } catch (Throwable $e) {
            throw new ConsultaSatException('El SAT no respondió: '.$e->getMessage(), 0, $e);
        } finally {
            if ($limite !== false) {
                ini_set('default_socket_timeout', $limite);
            }
        }

        $codigo = match (true) {
            $estado->query()->isNotFound(), $estado->document()->isNotFound() => ResultadoSat::NO_ENCONTRADO,
            $estado->document()->isCancelled() => ResultadoSat::CANCELADO,
            $estado->document()->isActive() => ResultadoSat::VIGENTE,
            default => ResultadoSat::NO_ENCONTRADO,
        };

        return new ResultadoSat(
            $codigo,
            (string) $estado->cancellable()->value(),
            (string) $estado->cancellation()->value(),
            mb_substr($expresion, 0, 255),
        );
    }
}
