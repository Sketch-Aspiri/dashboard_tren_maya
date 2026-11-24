<?php

namespace App\Services\Asistencia;

use App\Enums\EstatusAsistencia;
use App\Enums\TurnoAsistencia;
use App\Support\Texto;

/**
 * Lógica pura (sin red ni base de datos) para decidir qué celdas del Google Sheet de Coordinación se escriben.
 * Es la misma que tenía el nodo "Calcular" del flujo de n8n, con las filas saliendo del Dashboard en vez del Excel:
 * se busca la columna de hoy en el encabezado y la fila de cada persona por número de empleado (o por nombre),
 * y en la mañana solo se escriben quienes están en el primer turno.
 */
final class HojaAsistenciaCalculadora
{
    public const MODO_MANANA = 'manana';

    public const MODO_TARDE = 'tarde';

    private const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    /** Texto con el que cada estatus aparece en la hoja de Coordinación. */
    public static function textoDeHoja(EstatusAsistencia $estatus): string
    {
        return match ($estatus) {
            EstatusAsistencia::Presente, EstatusAsistencia::NuevoIngreso => 'PRESENTE',
            EstatusAsistencia::Descanso => 'DESCANSO',
            EstatusAsistencia::DiaNoLaborable => 'DIA NO LABORABLE',
            EstatusAsistencia::LicenciaMedica => 'LICENCIA MEDICA',
            EstatusAsistencia::Comision => 'COMISION',
            EstatusAsistencia::AvisoIncidencia => 'INCIDENCIA',
            EstatusAsistencia::ReporteAusentismo => 'AUSENTISMO',
            EstatusAsistencia::Vacaciones => 'VACACIONES',
            EstatusAsistencia::Baja => 'BAJA',
        };
    }

    /**
     * @param  list<array{estacion: string, nombre: string, no_empleado: string, estatus: ?EstatusAsistencia, turno: ?TurnoAsistencia}>  $filas  personal activo con su registro del día (null = sin capturar)
     * @param  list<list<mixed>>  $hoja  valores de la hoja, fila 1 = encabezado (fechas), columna A = número, B = nombre
     * @param  array{serial: int, dia: int, mes: int, iso: string}  $hoy  serial = fecha en número de Excel/Sheets
     * @return array{writes: list<array{range: string, values: list<list<string>>}>, reporte: array<string, mixed>}
     */
    public function calcular(array $filas, array $hoja, string $modo, array $hoy, string $nombreHoja): array
    {
        $reporte = ['modo' => $modo, 'fecha' => $hoy['iso'], 'hoja' => $nombreHoja, 'errores' => [], 'avisos' => []];

        $letra = $this->columnaDeHoy($hoja[0] ?? [], $hoy);

        if ($letra === null) {
            $reporte['errores'][] = 'No encontré la columna de hoy ('.$hoy['iso'].') en la hoja '.$nombreHoja.'.';

            return ['writes' => [], 'reporte' => $reporte];
        }

        $reporte['columna'] = $letra;
        [$porNumero, $porNombre] = $this->indices($hoja);

        $writes = [];
        $incompletos = $sinMatch = $numeroDistinto = $usadas = [];
        $omitidos = 0;
        $esperados = 0;

        foreach ($filas as $fila) {
            if ($fila['estatus'] === null || $fila['turno'] === null) {
                $incompletos[] = ['estacion' => $fila['estacion'], 'nombre' => $fila['nombre'], 'falta' => $fila['estatus'] === null ? 'ESTATUS' : 'TURNO'];

                continue;
            }

            if ($modo === self::MODO_MANANA && $fila['turno'] !== TurnoAsistencia::Primer) {
                $omitidos++;

                continue;
            }

            $esperados++;
            $numero = $this->normalizarNumero($fila['no_empleado']);
            $renglon = $porNumero[$numero] ?? null;

            if ($renglon === null) {
                $renglon = $porNombre[$this->normalizarNombre($fila['nombre'])] ?? null;

                if ($renglon !== null) {
                    $numeroDistinto[] = ['nombre' => $fila['nombre'], 'numero_dashboard' => $numero, 'numero_hoja' => $this->normalizarNumero($hoja[$renglon - 1][0] ?? null)];
                }
            }

            if ($renglon === null) {
                $sinMatch[] = ['estacion' => $fila['estacion'], 'nombre' => $fila['nombre'], 'no_empleado' => $numero];

                continue;
            }

            if (isset($usadas[$renglon])) {
                $reporte['avisos'][] = 'Persona repetida en el Dashboard: '.$fila['nombre'];

                continue;
            }

            $usadas[$renglon] = true;
            $writes[] = ['range' => "'".$nombreHoja."'!".$letra.$renglon, 'values' => [[self::textoDeHoja($fila['estatus'])]]];
        }

        $reporte += [
            'escritos' => count($writes),
            'esperados' => $esperados,
            'omitidos_por_turno' => $omitidos,
            'incompletos' => $incompletos,
            'sin_match_en_google' => $sinMatch,
            'numero_distinto_pero_mismo_nombre' => $numeroDistinto,
            'en_google_pero_no_en_dashboard' => $this->soloEnHoja($filas, $hoja),
        ];

        return ['writes' => $writes, 'reporte' => $reporte];
    }

    /**
     * Letra de la columna de hoy. El encabezado puede ser una fecha real (número) o texto tipo "16 noviembre".
     *
     * @param  list<mixed>  $encabezado
     * @param  array{serial: int, dia: int, mes: int, iso: string}  $hoy
     */
    private function columnaDeHoy(array $encabezado, array $hoy): ?string
    {
        foreach ($encabezado as $indice => $celda) {
            if ($indice >= 4 && $this->esHoy($celda, $hoy)) {
                return $this->letraDeColumna($indice);
            }
        }

        return null;
    }

    /** @param  array{serial: int, dia: int, mes: int, iso: string}  $hoy */
    private function esHoy(mixed $celda, array $hoy): bool
    {
        if (is_int($celda) || is_float($celda)) {
            return (int) floor($celda) === $hoy['serial'];
        }

        if (! is_string($celda)) {
            return false;
        }

        if (preg_match('/(\d{1,2})\s+(?:de\s+)?([a-z]+)/', Texto::normalizar($celda), $m) !== 1) {
            return false;
        }

        return (int) $m[1] === $hoy['dia'] && $m[2] === self::MESES[$hoy['mes'] - 1];
    }

    /**
     * @param  list<list<mixed>>  $hoja
     * @return array{0: array<string, int>, 1: array<string, int>} número → renglón y nombre → renglón (desde 1)
     */
    private function indices(array $hoja): array
    {
        $porNumero = $porNombre = [];

        foreach ($hoja as $i => $celdas) {
            if ($i === 0) {
                continue;
            }

            $numero = $this->normalizarNumero($celdas[0] ?? null);
            $nombre = $this->normalizarNombre($celdas[1] ?? null);

            if ($numero !== '') {
                $porNumero[$numero] = $i + 1;
            }

            if ($nombre !== '') {
                $porNombre[$nombre] = $i + 1;
            }
        }

        return [$porNumero, $porNombre];
    }

    /**
     * @param  list<array{nombre: string, no_empleado: string}>  $filas
     * @param  list<list<mixed>>  $hoja
     * @return list<array{no_empleado: string, nombre: string}>
     */
    private function soloEnHoja(array $filas, array $hoja): array
    {
        $numeros = array_flip(array_map(fn (array $f) => $this->normalizarNumero($f['no_empleado']), $filas));
        $nombres = array_flip(array_map(fn (array $f) => $this->normalizarNombre($f['nombre']), $filas));
        $solo = [];

        foreach ($hoja as $i => $celdas) {
            $numero = $this->normalizarNumero($celdas[0] ?? null);
            $nombre = $this->normalizarNombre($celdas[1] ?? null);

            if ($i === 0 || ($numero === '' && $nombre === '')) {
                continue;
            }

            if (! isset($numeros[$numero]) && ! isset($nombres[$nombre])) {
                $solo[] = ['no_empleado' => $numero, 'nombre' => trim((string) ($celdas[1] ?? ''))];
            }
        }

        return $solo;
    }

    private function normalizarNumero(mixed $valor): string
    {
        $texto = (string) preg_replace('/\.0+$/', '', (string) ($valor ?? ''));

        return preg_match('/\d+/', $texto, $m) === 1 ? $m[0] : '';
    }

    /** Mayúsculas, sin acentos ni puntuación y sin título (LIC, ING, DR…) al inicio. */
    private function normalizarNombre(mixed $valor): string
    {
        $texto = mb_strtoupper(Texto::normalizar($valor));
        $texto = (string) preg_replace('/[.,]/', ' ', $texto);
        $texto = (string) preg_replace('/^\s*((LIC|ING|DR|DRA|MTRO|MTRA|ARQ|CAP|C|TTE|SGTO|CMTE)\s+)+/', '', $texto);

        return trim((string) preg_replace('/\s+/', ' ', $texto));
    }

    private function letraDeColumna(int $indice): string
    {
        $letra = '';
        $n = $indice + 1;

        while ($n > 0) {
            $resto = ($n - 1) % 26;
            $letra = chr(65 + $resto).$letra;
            $n = intdiv($n - 1, 26);
        }

        return $letra;
    }
}
