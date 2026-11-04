<?php

namespace App\Services\Secciones\Hoja;

/**
 * Fórmulas de la hoja de cálculo ("=SUMA(A1:A5)"), del lado del servidor.
 * Aquí no se evalúan (eso lo hace el navegador, resources/js/hoja/formulas.js,
 * con la misma gramática). Solo se tokenizan para:
 *
 * - ajustar referencias al insertar o borrar filas/columnas (como Excel),
 * - traducirlas a una fórmula real de Excel al exportar, con una lista
 *   blanca de funciones (nada de HYPERLINK, WEBSERVICE ni DDE).
 *
 * Referencias: "A1" es la primera fila de datos de la hoja (posición 0) y
 * la primera columna. "$" fija la fila o la columna al copiar y pegar, pero
 * los cambios de estructura la ajustan igual (como Excel).
 */
final class FormulasHoja
{
    public const ERROR_REF = '#¡REF!';

    /** Funciones permitidas: nombre en español o inglés => nombre en Excel (inglés). */
    public const FUNCIONES = [
        'SUMA' => 'SUM', 'SUM' => 'SUM',
        'PROMEDIO' => 'AVERAGE', 'AVERAGE' => 'AVERAGE',
        'MIN' => 'MIN', 'MAX' => 'MAX',
        'CONTAR' => 'COUNT', 'COUNT' => 'COUNT',
        'CONTARA' => 'COUNTA', 'COUNTA' => 'COUNTA',
        'CONTAR.SI' => 'COUNTIF', 'COUNTIF' => 'COUNTIF',
        'SUMAR.SI' => 'SUMIF', 'SUMIF' => 'SUMIF',
        'SI' => 'IF', 'IF' => 'IF',
        'SI.ERROR' => 'IFERROR', 'IFERROR' => 'IFERROR',
        'Y' => 'AND', 'AND' => 'AND',
        'O' => 'OR', 'OR' => 'OR',
        'NO' => 'NOT', 'NOT' => 'NOT',
        'REDONDEAR' => 'ROUND', 'ROUND' => 'ROUND',
        'ENTERO' => 'INT', 'INT' => 'INT',
        'ABS' => 'ABS',
        'POTENCIA' => 'POWER', 'POWER' => 'POWER',
        'RAIZ' => 'SQRT', 'SQRT' => 'SQRT',
        'CONCATENAR' => 'CONCATENATE', 'CONCATENATE' => 'CONCATENATE', 'CONCAT' => 'CONCATENATE',
        'MAYUSC' => 'UPPER', 'UPPER' => 'UPPER',
        'MINUSC' => 'LOWER', 'LOWER' => 'LOWER',
        'LARGO' => 'LEN', 'LEN' => 'LEN',
        'HOY' => 'TODAY', 'TODAY' => 'TODAY',
        'AHORA' => 'NOW', 'NOW' => 'NOW',
    ];

    private const BOOLEANOS = ['VERDADERO' => 'TRUE', 'TRUE' => 'TRUE', 'FALSO' => 'FALSE', 'FALSE' => 'FALSE'];

    private const ERRORES_EXCEL = [
        '#¡REF!' => '#REF!', '#REF!' => '#REF!',
        '#¡DIV/0!' => '#DIV/0!', '#DIV/0!' => '#DIV/0!',
        '#¡VALOR!' => '#VALUE!', '#VALUE!' => '#VALUE!',
        '#N/A' => '#N/A',
    ];

    private const PATRON = '/\G(?:'
        .'(?<espacio>\s+)'
        .'|(?<texto>"(?:[^"]|"")*")'
        .'|(?<error>#(?:¡REF!|REF!|¡DIV\/0!|DIV\/0!|¡VALOR!|VALUE!|N\/A))'
        .'|(?<rango>\$?[A-Za-z]{1,3}\$?\d{1,7}:\$?[A-Za-z]{1,3}\$?\d{1,7})(?![A-Za-z0-9_.(])'
        .'|(?<celda>\$?[A-Za-z]{1,3}\$?\d{1,7})(?![A-Za-z0-9_.(])'
        .'|(?<numero>\d+(?:\.\d+)?(?:[eE][+-]?\d+)?|\.\d+)'
        .'|(?<nombre>[A-Za-zÁÉÍÓÚÑáéíóúñ_][A-Za-z0-9ÁÉÍÓÚÑáéíóúñ_.]*)'
        .'|(?<operador><>|<=|>=|[-+*\/^&=<>%(),;:])'
        .')/u';

    private const MAX_LARGO = 2000;

    public static function esFormula(mixed $valor): bool
    {
        return is_string($valor) && str_starts_with($valor, '=') && mb_strlen($valor) > 1;
    }

    /**
     * Tokens de la fórmula (sin el "=" inicial), o null si no se reconoce.
     *
     * @return list<array{tipo: string, texto: string}>|null
     */
    public static function tokens(string $formula): ?array
    {
        if (! self::esFormula($formula) || mb_strlen($formula) > self::MAX_LARGO) {
            return null;
        }

        $cuerpo = substr($formula, 1);
        $tokens = [];
        $posicion = 0;
        $largo = strlen($cuerpo);

        while ($posicion < $largo) {
            if (preg_match(self::PATRON, $cuerpo, $coincidencia, 0, $posicion) !== 1 || $coincidencia[0] === '') {
                return null;
            }

            foreach (['espacio', 'texto', 'error', 'rango', 'celda', 'numero', 'nombre', 'operador'] as $tipo) {
                if (isset($coincidencia[$tipo]) && $coincidencia[$tipo] !== '') {
                    $tokens[] = ['tipo' => $tipo, 'texto' => $coincidencia[$tipo]];
                    break;
                }
            }

            $posicion += strlen($coincidencia[0]);
        }

        return $tokens;
    }

    /**
     * Ajusta las referencias tras insertar ($delta > 0) o borrar ($delta < 0)
     * filas o columnas a partir de la posición $desde (0 = primera). Una
     * referencia a una celda borrada pasa a #¡REF!; un rango se recorta.
     * Devuelve la fórmula tal cual si no se puede leer.
     */
    public static function desplazar(string $formula, string $eje, int $desde, int $delta): string
    {
        $tokens = self::tokens($formula);

        if ($tokens === null || $delta === 0) {
            return $formula;
        }

        $resultado = '';

        foreach ($tokens as $token) {
            $resultado .= match ($token['tipo']) {
                'celda' => self::desplazarCelda($token['texto'], $eje, $desde, $delta),
                'rango' => self::desplazarRango($token['texto'], $eje, $desde, $delta),
                default => $token['texto'],
            };
        }

        return '='.$resultado;
    }

    /**
     * Fórmula equivalente para Excel (funciones en inglés, "," como
     * separador, filas +$filasEncabezado porque en el archivo la fila 1 son
     * los nombres de las columnas), o null si usa algo fuera de la lista
     * blanca (se exporta entonces como texto).
     */
    public static function aExcel(string $formula, int $filasEncabezado = 1): ?string
    {
        $tokens = self::tokens($formula);

        if ($tokens === null) {
            return null;
        }

        $resultado = '';
        $total = count($tokens);

        foreach ($tokens as $indice => $token) {
            $texto = $token['texto'];
            $siguiente = self::siguienteSignificativo($tokens, $indice, $total);

            $traducido = match ($token['tipo']) {
                'nombre' => $siguiente === '('
                    ? (self::FUNCIONES[mb_strtoupper($texto)] ?? null)
                    : (self::BOOLEANOS[mb_strtoupper($texto)] ?? null),
                'celda' => self::moverCelda($texto, $filasEncabezado, 0, respetarFijas: false),
                'rango' => self::moverRango($texto, $filasEncabezado, 0, respetarFijas: false),
                'error' => self::ERRORES_EXCEL[$texto] ?? null,
                'operador' => $texto === ';' ? ',' : ($texto === ':' ? null : $texto),
                default => $texto,
            };

            if ($traducido === null) {
                return null;
            }

            $resultado .= $traducido;
        }

        return '='.$resultado;
    }

    /**
     * Índice de columna (0 = A) desde sus letras.
     */
    public static function indiceColumna(string $letras): int
    {
        $indice = 0;

        foreach (str_split(strtoupper($letras)) as $letra) {
            $indice = $indice * 26 + (ord($letra) - 64);
        }

        return $indice - 1;
    }

    public static function letrasColumna(int $indice): string
    {
        $letras = '';
        $indice++;

        while ($indice > 0) {
            $resto = ($indice - 1) % 26;
            $letras = chr(65 + $resto).$letras;
            $indice = intdiv($indice - 1, 26);
        }

        return $letras;
    }

    /**
     * @param  list<array{tipo: string, texto: string}>  $tokens
     */
    private static function siguienteSignificativo(array $tokens, int $indice, int $total): ?string
    {
        for ($i = $indice + 1; $i < $total; $i++) {
            if ($tokens[$i]['tipo'] !== 'espacio') {
                return $tokens[$i]['texto'];
            }
        }

        return null;
    }

    /**
     * @return array{colFija: bool, col: int, filaFija: bool, fila: int}
     */
    private static function leerCelda(string $celda): array
    {
        preg_match('/^(\$?)([A-Za-z]{1,3})(\$?)(\d+)$/', $celda, $partes);

        return [
            'colFija' => $partes[1] === '$',
            'col' => self::indiceColumna($partes[2]),
            'filaFija' => $partes[3] === '$',
            'fila' => (int) $partes[4] - 1,
        ];
    }

    /**
     * @param  array{colFija: bool, col: int, filaFija: bool, fila: int}  $celda
     */
    private static function escribirCelda(array $celda): string
    {
        return ($celda['colFija'] ? '$' : '').self::letrasColumna($celda['col'])
            .($celda['filaFija'] ? '$' : '').($celda['fila'] + 1);
    }

    private static function moverCelda(string $texto, int $filas, int $columnas, bool $respetarFijas = true): string
    {
        $celda = self::leerCelda($texto);

        if (! ($respetarFijas && $celda['filaFija'])) {
            $celda['fila'] += $filas;
        }

        if (! ($respetarFijas && $celda['colFija'])) {
            $celda['col'] += $columnas;
        }

        return self::escribirCelda($celda);
    }

    private static function moverRango(string $texto, int $filas, int $columnas, bool $respetarFijas = true): string
    {
        [$inicio, $fin] = explode(':', $texto);

        return self::moverCelda($inicio, $filas, $columnas, $respetarFijas).':'.self::moverCelda($fin, $filas, $columnas, $respetarFijas);
    }

    private static function desplazarCelda(string $texto, string $eje, int $desde, int $delta): string
    {
        $celda = self::leerCelda($texto);
        $clave = $eje === 'fila' ? 'fila' : 'col';
        $posicion = $celda[$clave];

        if ($delta > 0) {
            $celda[$clave] = $posicion >= $desde ? $posicion + $delta : $posicion;

            return self::escribirCelda($celda);
        }

        $borradas = -$delta;

        if ($posicion >= $desde && $posicion < $desde + $borradas) {
            return self::ERROR_REF;
        }

        $celda[$clave] = $posicion >= $desde + $borradas ? $posicion - $borradas : $posicion;

        return self::escribirCelda($celda);
    }

    private static function desplazarRango(string $texto, string $eje, int $desde, int $delta): string
    {
        [$textoInicio, $textoFin] = explode(':', $texto);
        $inicio = self::leerCelda($textoInicio);
        $fin = self::leerCelda($textoFin);
        $clave = $eje === 'fila' ? 'fila' : 'col';
        $a = min($inicio[$clave], $fin[$clave]);
        $b = max($inicio[$clave], $fin[$clave]);

        if ($delta > 0) {
            $a = $a >= $desde ? $a + $delta : $a;
            $b = $b >= $desde ? $b + $delta : $b;
        } else {
            $borradas = -$delta;
            $limite = $desde + $borradas;
            $a = $a < $desde ? $a : ($a >= $limite ? $a - $borradas : $desde);
            $b = $b < $desde ? $b : ($b >= $limite ? $b - $borradas : $desde - 1);

            if ($b < $a) {
                return self::ERROR_REF;
            }
        }

        $inicio[$clave] = $a;
        $fin[$clave] = $b;

        return self::escribirCelda($inicio).':'.self::escribirCelda($fin);
    }
}
