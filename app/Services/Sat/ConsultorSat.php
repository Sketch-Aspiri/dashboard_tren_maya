<?php

namespace App\Services\Sat;

/**
 * Consulta el estado de un CFDI en el servicio público del SAT. Interfaz para
 * que las pruebas no dependan de la red ni de la extensión SOAP.
 */
interface ConsultorSat
{
    /**
     * @param  string  $xml  contenido del CFDI (de él se arma la expresión impresa)
     *
     * @throws ConsultaSatException Si el SAT no responde o la respuesta no se entiende.
     */
    public function consultar(string $xml): ResultadoSat;
}
