<?php

namespace App\Services\Secciones;

use App\Enums\TipoColumna;
use App\Support\Texto;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Convierte valores crudos (de un Excel, Word, PDF o del formulario) al
 * formato que se guarda en seccion_filas.datos según el tipo de columna,
 * e infiere el tipo de una columna nueva a partir de sus valores.
 */
final class NormalizadorValores
{
    private const MESES = [
        'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6,
        'julio' => 7, 'agosto' => 8, 'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10,
        'noviembre' => 11, 'diciembre' => 12,
        'ene' => 1, 'feb' => 2, 'mar' => 3, 'abr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7,
        'ago' => 8, 'sep' => 9, 'sept' => 9, 'oct' => 10, 'nov' => 11, 'dic' => 12,
    ];

    private const VERDADEROS = ['si', 'sí', 's', 'x', 'true', 'verdadero', '1', 'yes', 'ok', 'activo'];

    private const FALSOS = ['no', 'n', 'false', 'falso', '0', 'inactivo'];

    private const MAX_TEXTO = 5000;

    /**
     * Valor normalizado para guardar, o null si está vacío. Si no se puede
     * convertir al tipo pedido, conserva el texto original (nunca se pierde
     * información por un formato inesperado).
     */
    public function normalizar(mixed $valor, TipoColumna $tipo): string|int|float|bool|null
    {
        if ($valor === null || (is_string($valor) && trim($valor) === '')) {
            return null;
        }

        $convertido = match ($tipo) {
            TipoColumna::Numero => $this->numero($valor),
            TipoColumna::Fecha => $this->fecha($valor),
            TipoColumna::Booleano => $this->booleano($valor),
            TipoColumna::Telefono => $this->telefono($valor),
            TipoColumna::Texto, TipoColumna::Lista => null,
        };

        return $convertido ?? $this->texto($valor);
    }

    public function texto(mixed $valor): string
    {
        if (is_bool($valor)) {
            return $valor ? 'Sí' : 'No';
        }

        // UTF-8 inválido (texto de PDF/Word) haría fallar preg_replace y json_encode.
        $texto = mb_convert_encoding((string) $valor, 'UTF-8', 'UTF-8');
        $texto = trim(preg_replace('/[ \t]+/u', ' ', $texto) ?? $texto);

        return mb_substr($texto, 0, self::MAX_TEXTO);
    }

    public function numero(mixed $valor): int|float|null
    {
        if (is_int($valor) || is_float($valor)) {
            return $valor;
        }

        if (! is_string($valor)) {
            return null;
        }

        $limpio = str_replace(['$', ' ', "\u{00A0}", 'MXN', 'mxn'], '', trim($valor));
        $esPorcentaje = str_ends_with($limpio, '%');
        $limpio = rtrim($limpio, '%');

        // "1,234.56" (México) -> quitar comas de miles.
        if (preg_match('/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $limpio)) {
            $limpio = str_replace(',', '', $limpio);
        } elseif (preg_match('/^-?\d+,\d+$/', $limpio)) {
            // "12,5" -> coma decimal.
            $limpio = str_replace(',', '.', $limpio);
        }

        if (! is_numeric($limpio)) {
            return null;
        }

        $numero = $limpio + 0;

        return $esPorcentaje ? $numero / 100 : $numero;
    }

    /**
     * Fecha como "Y-m-d". Acepta "Y-m-d", "d/m/Y", "d-m-Y", "d/m/y",
     * "15 de septiembre de 2026", "15-sep-26" y números de serie de Excel
     * (solo cuando el valor ya es numérico, p. ej. de una celda de fecha).
     */
    public function fecha(mixed $valor): ?string
    {
        if ($valor instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($valor)->toDateString();
        }

        if (is_int($valor) || is_float($valor)) {
            return ($valor > 20000 && $valor < 80000)
                ? CarbonImmutable::instance(ExcelDate::excelToDateTimeObject($valor))->toDateString()
                : null;
        }

        $texto = Texto::normalizar($valor);

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $texto, $m)) {
            return $this->armarFecha((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})$/', $texto, $m)) {
            return $this->armarFecha($this->anio($m[3]), (int) $m[2], (int) $m[1]);
        }

        if (preg_match('/^(\d{1,2})(?:\s+de\s+|[\s\-\/.]+)([a-z]+)\.?(?:\s+de\s+|\s+del\s+|[\s\-\/.]+)(\d{2,4})$/', $texto, $m)
            && isset(self::MESES[$m[2]])) {
            return $this->armarFecha($this->anio($m[3]), self::MESES[$m[2]], (int) $m[1]);
        }

        return null;
    }

    /**
     * Teléfono mexicano de 10 dígitos como "983 168 7870" (más " ext. 123"
     * si trae extensión). Acepta cualquier separador ("983-168-7870",
     * "(983) 168 78 70"), el prefijo +52 / 52 / 044 / 045 / 01 y los números
     * que Excel guarda como cifra. Si no es un teléfono de 10 dígitos
     * devuelve null (quien llama conserva el texto original).
     */
    public function telefono(mixed $valor): ?string
    {
        if (is_int($valor) || is_float($valor)) {
            $texto = number_format((float) $valor, 0, '', '');
        } elseif (is_string($valor)) {
            $texto = trim($valor);
        } else {
            return null;
        }

        $extension = null;

        if (preg_match('/^(.*?)[\s,;]*(?:ext(?:ensi[oó]n)?\.?|x)\s*[:.]?\s*(\d{1,6})$/iu', $texto, $partes)) {
            [, $texto, $extension] = $partes;
        }

        // Cualquier letra restante (p. ej. "llamar a Juan") no es un teléfono.
        if ($texto === '' || preg_match('/\p{L}/u', $texto)) {
            return null;
        }

        $digitos = preg_replace('/\D/', '', $texto) ?? '';
        $digitos = match (true) {
            strlen($digitos) === 13 && in_array(substr($digitos, 0, 3), ['521', '044', '045'], true) => substr($digitos, 3),
            strlen($digitos) === 12 && in_array(substr($digitos, 0, 2), ['52', '01'], true) => substr($digitos, 2),
            default => $digitos,
        };

        if (strlen($digitos) !== 10) {
            return null;
        }

        return sprintf('%s %s %s', substr($digitos, 0, 3), substr($digitos, 3, 3), substr($digitos, 6))
            .($extension !== null ? " ext. {$extension}" : '');
    }

    public function booleano(mixed $valor): ?bool
    {
        if (is_bool($valor)) {
            return $valor;
        }

        $texto = Texto::normalizar($valor);

        return match (true) {
            in_array($texto, self::VERDADEROS, true) => true,
            in_array($texto, self::FALSOS, true) => false,
            default => null,
        };
    }

    /**
     * Tipo sugerido para una columna nueva: el que convierte al menos el
     * 90% de sus valores no vacíos. Una columna con pocos valores
     * distintos sigue siendo "texto" (las listas se definen a mano).
     *
     * Teléfono si el encabezado lo sugiere ("Teléfono", "Celular"…) o si los
     * valores traen separadores (un número de 10 cifras sin más sigue siendo
     * número: podría ser un folio).
     *
     * @param  list<mixed>  $valores
     */
    public function inferirTipo(array $valores, ?string $encabezado = null): TipoColumna
    {
        $noVacios = array_values(array_filter(
            $valores,
            fn ($valor) => $valor !== null && trim((string) $valor) !== '',
        ));

        if ($noVacios === []) {
            return TipoColumna::Texto;
        }

        $umbral = (int) ceil(count($noVacios) * 0.9);

        $cuenta = fn (callable $conversion) => count(array_filter($noVacios, fn ($valor) => $conversion($valor) !== null));

        // Fecha antes que número: un serial de Excel también es número, pero
        // los serial solo llegan como fecha si el extractor ya los convirtió.
        $pareceTelefono = $encabezado !== null && preg_match('/tel[eé]f|tel\.?|celular|m[oó]vil|whats|fono|extensi/iu', $encabezado) === 1;
        $telefonos = $cuenta(fn ($valor) => ($pareceTelefono || (is_string($valor) && preg_match('/\D/', $valor) === 1)) ? $this->telefono($valor) : null);

        return match (true) {
            $telefonos >= $umbral => TipoColumna::Telefono,
            $cuenta(fn ($valor) => is_string($valor) ? $this->fecha($valor) : null) >= $umbral => TipoColumna::Fecha,
            $cuenta(fn ($valor) => $this->numero($valor)) >= $umbral => TipoColumna::Numero,
            $cuenta(fn ($valor) => in_array(Texto::normalizar($valor), ['si', 'no'], true) ? true : null) >= $umbral => TipoColumna::Booleano,
            default => TipoColumna::Texto,
        };
    }

    /**
     * Valor para mostrar en pantalla / exportar.
     */
    public function paraMostrar(mixed $valor, TipoColumna $tipo): string
    {
        if ($valor === null || $valor === '') {
            return '';
        }

        return match (true) {
            $tipo === TipoColumna::Booleano && is_bool($valor) => $valor ? 'Sí' : 'No',
            $tipo === TipoColumna::Fecha && is_string($valor) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) === 1 => CarbonImmutable::parse($valor)->format('d/m/Y'),
            $tipo === TipoColumna::Numero && (is_int($valor) || is_float($valor)) => $this->formatoNumero($valor),
            is_bool($valor) => $valor ? 'Sí' : 'No',
            default => (string) $valor,
        };
    }

    private function formatoNumero(int|float $valor): string
    {
        if (is_int($valor) || floor($valor) === $valor) {
            return number_format($valor, 0, '.', ',');
        }

        return rtrim(rtrim(number_format($valor, 4, '.', ','), '0'), '.');
    }

    private function anio(string $anio): int
    {
        $numero = (int) $anio;

        return strlen($anio) === 2 ? 2000 + $numero : $numero;
    }

    private function armarFecha(int $anio, int $mes, int $dia): ?string
    {
        if (! checkdate($mes, $dia, $anio) || $anio < 1900 || $anio > 2200) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $anio, $mes, $dia);
    }
}
