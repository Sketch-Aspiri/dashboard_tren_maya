<?php

namespace App\Services;

use App\Models\CatalogoInsumo;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Carga del catálogo de insumos desde un ANEXO mensual ("ZO_Cotización"):
 * lee las partidas (N°, Cant., Unidad, Descripción, P.U., P.Ptal) y las
 * agrega o actualiza en el catálogo. La columna "Proveedor" del ANEXO mezcla
 * nombres de proveedor con comentarios de la Coordinación ("para que es?"):
 * solo se toma cuando parece un nombre (ver proveedor()).
 */
final class CatalogoInsumoService
{
    private const MAX_FILAS = 3000;

    /** Variante (sin acentos, en minúsculas) => forma canónica. */
    private const UNIDADES = [
        'pza' => 'Pieza', 'pzas' => 'Pieza', 'pieza' => 'Pieza', 'piezas' => 'Pieza',
        'gal' => 'Galón', 'galon' => 'Galón', 'galones' => 'Galón',
        'cuneta' => 'Cuñete', 'cunete' => 'Cuñete', 'cuneta ' => 'Cuñete',
        'kg' => 'Kg', 'kilo' => 'Kg', 'kilos' => 'Kg', 'kilogramo' => 'Kg',
        'lt' => 'Litro', 'lts' => 'Litro', 'litro' => 'Litro', 'litros' => 'Litro',
        'mt' => 'Metro', 'mts' => 'Metro', 'metro' => 'Metro', 'metros' => 'Metro',
        'paq' => 'Paquete', 'paquete' => 'Paquete', 'paquetes' => 'Paquete',
    ];

    /**
     * @return array{leidos: int, creados: int, actualizados: int, sin_cambio: int}
     */
    public function importarAnexo(string $ruta): array
    {
        $filas = $this->leerAnexo($ruta);
        $resumen = ['leidos' => count($filas), 'creados' => 0, 'actualizados' => 0, 'sin_cambio' => 0];

        foreach ($filas as $fila) {
            $insumo = CatalogoInsumo::query()->where('descripcion', $fila['descripcion'])->first();

            if ($insumo === null) {
                CatalogoInsumo::query()->create([
                    'descripcion' => $fila['descripcion'],
                    'unidad' => $fila['unidad'],
                    'ultimo_precio' => $fila['precio'],
                    'partida_presupuestal' => $fila['partida'],
                    'proveedor' => $fila['proveedor'],
                ]);
                $resumen['creados']++;

                continue;
            }

            $insumo->ultimo_precio = $fila['precio'];
            if ($insumo->partida_presupuestal === null && $fila['partida'] !== null) {
                $insumo->partida_presupuestal = $fila['partida'];
            }
            if ($insumo->proveedor === null && $fila['proveedor'] !== null) {
                $insumo->proveedor = $fila['proveedor'];
            }

            if ($insumo->isDirty()) {
                $insumo->save();
                $resumen['actualizados']++;
            } else {
                $resumen['sin_cambio']++;
            }
        }

        return $resumen;
    }

    /**
     * Filas válidas del ANEXO, sin repetir descripción (gana la última).
     *
     * @return list<array{descripcion: string, unidad: string, precio: float, partida: string|null, proveedor: string|null}>
     */
    public function leerAnexo(string $ruta): array
    {
        $hoja = $this->abrirHojaAnexo($ruta);

        $filas = [];
        $limite = min($hoja->getHighestRow(), self::MAX_FILAS);

        for ($i = 1; $i <= $limite; $i++) {
            $fila = $this->leerFila($hoja, $i);

            if ($fila !== null) {
                $filas[mb_strtolower($fila['descripcion'])] = $fila;
            }
        }

        return array_values($filas);
    }

    /**
     * Hoja "ZO_Cotización" (o la primera visible) de un ANEXO .xlsx; la usan
     * la carga del catálogo y la respuesta de la Coordinación.
     */
    public function abrirHojaAnexo(string $ruta): Worksheet
    {
        $lector = new Xlsx;
        $lector->setReadDataOnly(true);

        return $this->hojaDeCotizacion($lector->load($ruta)->getAllSheets());
    }

    /**
     * @param  array<int, Worksheet>  $hojas
     */
    private function hojaDeCotizacion(array $hojas): Worksheet
    {
        $visibles = array_values(array_filter($hojas, fn (Worksheet $h) => $h->getSheetState() === Worksheet::SHEETSTATE_VISIBLE));

        foreach ($visibles as $hoja) {
            if (str_starts_with(mb_strtoupper($hoja->getTitle()), 'ZO_')) {
                return $hoja;
            }
        }

        return $visibles[0] ?? $hojas[0];
    }

    /**
     * @return array{descripcion: string, unidad: string, precio: float, partida: string|null, proveedor: string|null}|null
     */
    private function leerFila(Worksheet $hoja, int $fila): ?array
    {
        $numero = $hoja->getCell('B'.$fila)->getValue();
        $descripcion = $this->limpiar($hoja->getCell('E'.$fila)->getValue());
        $precio = $hoja->getCell('F'.$fila)->getValue();

        if (! is_numeric($numero) || $descripcion === '' || ! is_numeric($precio) || (float) $precio <= 0) {
            return null;
        }

        $unidad = $this->limpiar($hoja->getCell('D'.$fila)->getValue());
        $partida = $this->partida($hoja->getCell('H'.$fila)->getValue())
            ?? $this->partida($hoja->getCell('I'.$fila)->getValue());

        return [
            'descripcion' => mb_substr($descripcion, 0, 255),
            'unidad' => $this->unidad($unidad),
            'precio' => round((float) $precio, 2),
            'partida' => $partida,
            'proveedor' => $this->proveedor($hoja->getCell('K'.$fila)->getValue()),
        ];
    }

    /**
     * Unifica variantes de escritura de la misma unidad (Gal, Galon, Galón...).
     */
    private function unidad(string $unidad): string
    {
        if ($unidad === '') {
            return 'Pieza';
        }

        $clave = Str::ascii(mb_strtolower($unidad));

        return self::UNIDADES[$clave] ?? mb_substr(mb_convert_case(mb_strtolower($unidad), MB_CASE_TITLE), 0, 30);
    }

    /**
     * Un nombre de proveedor es corto, sin signos de pregunta ni comas; lo
     * demás se considera comentario de la Coordinación y se descarta.
     */
    private function proveedor(mixed $valor): ?string
    {
        $texto = $this->limpiar($valor);

        if ($texto === '' || mb_strlen($texto) > 40 || str_contains($texto, '?') || str_contains($texto, ',') || count(explode(' ', $texto)) > 4) {
            return null;
        }

        return mb_convert_case(mb_strtolower($texto), MB_CASE_TITLE);
    }

    private function limpiar(mixed $valor): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $valor));
    }

    private function partida(mixed $valor): ?string
    {
        $texto = $this->limpiar($valor);

        return preg_match('/^\d{5}$/', $texto) === 1 ? $texto : null;
    }
}
