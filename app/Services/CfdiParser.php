<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;
use InvalidArgumentException;

/**
 * Lee un CFDI (versiones 3.3 y 4.0) desde su XML sin IA: UUID, emisor,
 * receptor, conceptos, impuestos (IVA trasladado e ISR retenido) y la
 * referencia a la cotización que el proveedor anota en las notas ("COT - 196").
 *
 * Seguridad: el XML viene de fuera, así que se rechaza todo documento con
 * DOCTYPE o ENTITY (XXE / expansión de entidades), no se cargan recursos de
 * red y se limita el tamaño.
 */
final class CfdiParser
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    private const IMPUESTO_IVA = '002';

    private const IMPUESTO_ISR = '001';

    /**
     * @return array{
     *     version: string|null, serie: string|null, folio: string|null, fecha: string, fecha_timbrado: string|null,
     *     emisor_rfc: string, emisor_nombre: string|null, receptor_rfc: string, receptor_nombre: string|null,
     *     tipo_comprobante: string, moneda: string, metodo_pago: string|null,
     *     subtotal: float, descuento: float, iva: float, isr_retenido: float, otros_retenidos: float, total: float,
     *     uuid: string, referencia_cot: string|null,
     *     conceptos: list<array{clave_prod_serv: string|null, no_identificacion: string|null, unidad: string|null, descripcion: string, cantidad: float, valor_unitario: float, descuento: float, importe: float, iva_tasa: float|null, iva_importe: float}>
     * }
     *
     * @throws InvalidArgumentException Si el archivo no es un CFDI timbrado y válido.
     */
    public function leer(string $xml): array
    {
        if (strlen($xml) > self::MAX_BYTES) {
            throw new InvalidArgumentException('El XML es demasiado grande (máximo 2 MB).');
        }

        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new InvalidArgumentException('El XML contiene una declaración DOCTYPE o ENTITY, que no se permite.');
        }

        $dom = new DOMDocument;
        $previo = libxml_use_internal_errors(true);
        $cargado = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previo);

        if (! $cargado) {
            throw new InvalidArgumentException('El archivo no es un XML válido.');
        }

        $xpath = new DOMXPath($dom);
        $comprobante = $xpath->query('/*[local-name()="Comprobante"]')->item(0);

        if (! $comprobante instanceof DOMElement) {
            throw new InvalidArgumentException('El archivo no es un CFDI (falta el nodo Comprobante).');
        }

        $emisor = $xpath->query('./*[local-name()="Emisor"]', $comprobante)->item(0);
        $receptor = $xpath->query('./*[local-name()="Receptor"]', $comprobante)->item(0);
        $timbre = $xpath->query('.//*[local-name()="TimbreFiscalDigital"]', $comprobante)->item(0);

        if (! $emisor instanceof DOMElement || ! $receptor instanceof DOMElement) {
            throw new InvalidArgumentException('El CFDI no trae emisor o receptor.');
        }

        $uuid = $timbre instanceof DOMElement ? strtoupper($this->atributo($timbre, 'UUID') ?? '') : '';

        if (preg_match('/^[0-9A-F]{8}-(?:[0-9A-F]{4}-){3}[0-9A-F]{12}$/', $uuid) !== 1) {
            throw new InvalidArgumentException('El CFDI no está timbrado (no trae un UUID válido).');
        }

        $conceptos = $this->conceptos($xpath, $comprobante);
        [$iva, $isr, $otros] = $this->impuestosGlobales($xpath, $comprobante, $conceptos);

        return [
            'version' => $this->atributo($comprobante, 'Version') ?? $this->atributo($comprobante, 'version'),
            'serie' => $this->atributo($comprobante, 'Serie'),
            'folio' => $this->atributo($comprobante, 'Folio'),
            'fecha' => $this->fecha($this->atributo($comprobante, 'Fecha')) ?? throw new InvalidArgumentException('El CFDI no trae una fecha válida.'),
            'fecha_timbrado' => $timbre instanceof DOMElement ? $this->fecha($this->atributo($timbre, 'FechaTimbrado')) : null,
            'emisor_rfc' => strtoupper($this->atributo($emisor, 'Rfc') ?? ''),
            'emisor_nombre' => $this->atributo($emisor, 'Nombre'),
            'receptor_rfc' => strtoupper($this->atributo($receptor, 'Rfc') ?? ''),
            'receptor_nombre' => $this->atributo($receptor, 'Nombre'),
            'tipo_comprobante' => strtoupper($this->atributo($comprobante, 'TipoDeComprobante') ?? 'I'),
            'moneda' => strtoupper($this->atributo($comprobante, 'Moneda') ?? 'MXN'),
            'metodo_pago' => $this->atributo($comprobante, 'MetodoPago'),
            'subtotal' => $this->numero($this->atributo($comprobante, 'SubTotal')),
            'descuento' => $this->numero($this->atributo($comprobante, 'Descuento')),
            'iva' => $iva,
            'isr_retenido' => $isr,
            'otros_retenidos' => $otros,
            'total' => $this->numero($this->atributo($comprobante, 'Total')),
            'uuid' => $uuid,
            'referencia_cot' => $this->referenciaCotizacion($xml),
            'conceptos' => $conceptos,
        ];
    }

    /**
     * @return list<array{clave_prod_serv: string|null, no_identificacion: string|null, unidad: string|null, descripcion: string, cantidad: float, valor_unitario: float, descuento: float, importe: float, iva_tasa: float|null, iva_importe: float}>
     */
    private function conceptos(DOMXPath $xpath, DOMElement $comprobante): array
    {
        $conceptos = [];

        foreach ($xpath->query('./*[local-name()="Conceptos"]/*[local-name()="Concepto"]', $comprobante) as $nodo) {
            $iva = null;
            $ivaImporte = 0.0;

            foreach ($xpath->query('./*[local-name()="Impuestos"]/*[local-name()="Traslados"]/*[local-name()="Traslado"]', $nodo) as $traslado) {
                if ($this->atributo($traslado, 'Impuesto') === self::IMPUESTO_IVA) {
                    $iva = round($this->numero($this->atributo($traslado, 'TasaOCuota')) * 100, 2);
                    $ivaImporte += $this->numero($this->atributo($traslado, 'Importe'));
                }
            }

            $conceptos[] = [
                'clave_prod_serv' => $this->atributo($nodo, 'ClaveProdServ'),
                'no_identificacion' => $this->atributo($nodo, 'NoIdentificacion'),
                'unidad' => $this->atributo($nodo, 'Unidad') ?? $this->atributo($nodo, 'ClaveUnidad'),
                'descripcion' => mb_substr((string) $this->atributo($nodo, 'Descripcion'), 0, 2000),
                'cantidad' => $this->numero($this->atributo($nodo, 'Cantidad'), 4),
                'valor_unitario' => $this->numero($this->atributo($nodo, 'ValorUnitario'), 4),
                'descuento' => $this->numero($this->atributo($nodo, 'Descuento')),
                'importe' => $this->numero($this->atributo($nodo, 'Importe')),
                'iva_tasa' => $iva,
                'iva_importe' => round($ivaImporte, 2),
            ];
        }

        if ($conceptos === []) {
            throw new InvalidArgumentException('El CFDI no trae conceptos.');
        }

        return $conceptos;
    }

    /**
     * IVA trasladado, ISR retenido y otras retenciones del comprobante.
     *
     * @param  list<array<string, mixed>>  $conceptos
     * @return array{0: float, 1: float, 2: float}
     */
    private function impuestosGlobales(DOMXPath $xpath, DOMElement $comprobante, array $conceptos): array
    {
        $iva = 0.0;
        $encontrado = false;

        foreach ($xpath->query('./*[local-name()="Impuestos"]/*[local-name()="Traslados"]/*[local-name()="Traslado"]', $comprobante) as $traslado) {
            $encontrado = true;

            if ($this->atributo($traslado, 'Impuesto') === self::IMPUESTO_IVA) {
                $iva += $this->numero($this->atributo($traslado, 'Importe'));
            }
        }

        if (! $encontrado) {
            $iva = array_sum(array_column($conceptos, 'iva_importe'));
        }

        $isr = 0.0;
        $otros = 0.0;

        foreach ($xpath->query('./*[local-name()="Impuestos"]/*[local-name()="Retenciones"]/*[local-name()="Retencion"]', $comprobante) as $retencion) {
            $importe = $this->numero($this->atributo($retencion, 'Importe'));
            $this->atributo($retencion, 'Impuesto') === self::IMPUESTO_ISR ? $isr += $importe : $otros += $importe;
        }

        return [round($iva, 2), round($isr, 2), round($otros, 2)];
    }

    /**
     * Número de cotización que el proveedor anota en las notas o en la addenda
     * ("COT - 196 - RI - 135"). Se busca en todo el texto del XML.
     */
    private function referenciaCotizacion(string $xml): ?string
    {
        return preg_match('/\bCOT\s*[-–:.]?\s*(\d{1,10})\b/iu', $xml, $m) === 1 ? ltrim($m[1], '0') ?: '0' : null;
    }

    private function atributo(DOMElement $nodo, string $nombre): ?string
    {
        if (! $nodo->hasAttribute($nombre)) {
            return null;
        }

        $valor = trim($nodo->getAttribute($nombre));

        return $valor === '' ? null : $valor;
    }

    private function numero(?string $valor, int $decimales = 2): float
    {
        return is_numeric($valor) ? round((float) $valor, $decimales) : 0.0;
    }

    private function fecha(?string $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $tiempo = strtotime($valor);

        return $tiempo === false ? null : date('Y-m-d H:i:s', $tiempo);
    }
}
