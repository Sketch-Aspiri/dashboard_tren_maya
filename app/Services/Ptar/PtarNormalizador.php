<?php

namespace App\Services\Ptar;

use App\Models\PtarEquipo;
use App\Models\PtarRegistro;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Convierte el texto libre del Excel de la PTAR (estatus como "OP: OPERANDO", "opernado" o "1 de 2 fuera de
 * servicio"; fechas como "29/09/2026" o "Cada dos dias (05/08/2026)"; marcas "X" de modo) a los valores del módulo.
 */
final class PtarNormalizador
{
    /** Estatus de un equipo a partir del texto de la celda ("sin dato" si está vacío o no se entiende). */
    public static function estatus(?string $texto): string
    {
        $t = mb_strtolower(trim((string) $texto));

        if ($t === '') {
            return PtarEquipo::SIN_DATO;
        }

        if (str_contains($t, 'fuera')) {
            // "1 de 2 fuera de servicio": solo una parte del equipo está parada.
            return preg_match('/\d/', $t) === 1 ? PtarEquipo::PARCIAL : PtarEquipo::FUERA_SERVICIO;
        }

        if (str_contains($t, 'parcial')) {
            return PtarEquipo::PARCIAL;
        }

        return str_starts_with(ltrim(str_replace('op:', '', $t)), 'oper') || str_contains($t, 'opern') ? PtarEquipo::OPERANDO : PtarEquipo::SIN_DATO;
    }

    /**
     * Fecha de mantenimiento: devuelve la fecha (la última si el texto trae varias) y la nota cuando el texto no
     * es solo una fecha ("Diario", "N/A", "No se ha requerido…", "Cada dos dias (05/08/2026)").
     *
     * @return array{0: string|null, 1: string|null} [fecha Y-m-d, nota]
     */
    public static function fecha(DateTimeInterface|string|null $valor): array
    {
        if ($valor instanceof DateTimeInterface) {
            return [CarbonImmutable::instance($valor)->toDateString(), null];
        }

        $texto = trim((string) $valor);

        if ($texto === '') {
            return [null, null];
        }

        $fecha = null;
        if (preg_match_all('#(\d{1,2})/(\d{1,2})/(\d{4})#', $texto, $coincidencias, PREG_SET_ORDER) > 0) {
            $ultima = end($coincidencias);
            if (checkdate((int) $ultima[2], (int) $ultima[1], (int) $ultima[3])) {
                $fecha = sprintf('%04d-%02d-%02d', $ultima[3], $ultima[2], $ultima[1]);
            }
        }

        $soloFecha = $fecha !== null && preg_match('#^\d{1,2}/\d{1,2}/\d{4}$#', $texto) === 1;

        return [$fecha, $soloFecha ? null : mb_substr(preg_replace('/\s+/u', ' ', $texto), 0, 255)];
    }

    /**
     * Modo de operación según las columnas Automático / Manual / Fuera de servicio de la hoja.
     * Manual manda si está marcada; "Fuera de servicio" solo cuando es la única marca. El detalle conserva el texto
     * libre (equipos que operan en manual o están fuera de servicio).
     *
     * @return array{0: string, 1: string|null} [modo, detalle]
     */
    public static function modo(?string $automatico, ?string $manual, ?string $fuera): array
    {
        $auto = trim((string) $automatico) !== '';
        $man = trim((string) $manual) !== '';
        $out = trim((string) $fuera) !== '';

        $detalle = [];
        if ($auto && $man) {
            $detalle[] = 'Automático y manual';
        }
        if ($man && ! self::esMarca($manual)) {
            $detalle[] = 'Manual: '.trim((string) $manual);
        }
        if ($out && ! self::esMarca($fuera)) {
            $detalle[] = 'Fuera de servicio: '.trim((string) $fuera);
        }

        $modo = match (true) {
            $man => PtarRegistro::MODO_MANUAL,
            $auto => PtarRegistro::MODO_AUTOMATICO,
            $out => PtarRegistro::MODO_FUERA_SERVICIO,
            default => PtarRegistro::MODO_SIN_DATO,
        };

        return [$modo, $detalle === [] ? null : mb_substr(implode('. ', $detalle), 0, 255)];
    }

    /** Equipo del bloque según su orden en la hoja (la etiqueta de la columna "Equipo" a veces falta). */
    public static function claveEquipo(int $posicion): ?string
    {
        return array_keys(config('ptar.equipos'))[$posicion] ?? null;
    }

    /** "Cárcamo", "sedimentador " -> primera letra en mayúscula, sin espacios sobrantes. */
    public static function actividad(string $texto): string
    {
        $limpio = trim(preg_replace('/\s+/u', ' ', $texto));

        return mb_strtoupper(mb_substr($limpio, 0, 1)).mb_substr($limpio, 1);
    }

    private static function esMarca(?string $texto): bool
    {
        return mb_strtolower(trim((string) $texto)) === 'x';
    }
}
