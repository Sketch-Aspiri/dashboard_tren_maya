<?php

namespace App\Services\Asistencia;

/**
 * Lo mínimo que se necesita de Google Sheets para escribir la asistencia. Existe como contrato para que las pruebas
 * usen un cliente falso y nunca salgan a internet.
 */
interface HojaGoogleCliente
{
    /**
     * Valores sin formato (las fechas llegan como número de serie) del rango, p. ej. 'Noviembre'!A1:AO300.
     *
     * @return list<list<mixed>>
     */
    public function leer(string $spreadsheetId, string $rango): array;

    /**
     * Escribe celdas sueltas con un solo batchUpdate (valores RAW).
     *
     * @param  list<array{range: string, values: list<list<string>>}>  $celdas
     */
    public function escribir(string $spreadsheetId, array $celdas): void;
}
