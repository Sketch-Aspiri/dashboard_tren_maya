<?php

namespace Tests\Concerns;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Revisión estructural de una pantalla ya generada por el servidor (parte de WCAG 2.2 que se puede
 * comprobar sin navegador): idioma, título, un h1, nombres de controles, botones, enlaces e imágenes,
 * ids sin duplicar, referencias aria que existen, tablas con nombre y encabezados con scope.
 *
 * Lo que Alpine pinta en el navegador (atributos ":x" o "x-bind:x", x-text) cuenta como presente: su
 * valor se comprueba con tools/capturas/a11y.mjs, no aquí.
 */
trait RevisaAccesibilidad
{
    /**
     * @return list<string> problemas encontrados (vacío si la pantalla cumple)
     */
    protected function problemasDeAccesibilidad(string $html): array
    {
        $xpath = $this->documento($html);
        $problemas = [];

        $raiz = $xpath->query('//html')->item(0);
        if (! $raiz instanceof DOMElement || trim($raiz->getAttribute('lang')) === '') {
            $problemas[] = '<html> sin lang';
        }
        if (trim((string) $xpath->evaluate('string(//head/title)')) === '') {
            $problemas[] = 'sin <title>';
        }
        $h1 = $xpath->query('//h1')->length;
        if ($h1 !== 1) {
            $problemas[] = "{$h1} elementos h1";
        }

        return [
            ...$problemas,
            ...$this->idsDuplicados($xpath),
            ...$this->referenciasRotas($xpath),
            ...$this->controlesSinNombre($xpath),
            ...$this->botonesYEnlacesSinNombre($xpath),
            ...$this->tablasEImagenes($xpath),
            ...$this->enviosAutomaticos($xpath),
        ];
    }

    private function documento(string $html): DOMXPath
    {
        // libxml (HTML 4) no admite "@click"; los scripts y los dibujos no se revisan.
        $html = (string) preg_replace('~<script\b[^>]*>.*?</script>|<style\b[^>]*>.*?</style>~s', '', $html);
        $html = (string) preg_replace('~<svg\b.*?</svg>~s', '<i></i>', $html);
        $html = (string) preg_replace('~(\s)@([a-z][\w.:-]*)(?==|\s|>)~', '$1data-al-$2', $html);

        $anterior = libxml_use_internal_errors(true);
        $documento = new DOMDocument;
        $documento->loadHTML('<?xml encoding="utf-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);

        return new DOMXPath($documento);
    }

    /** @return list<string> */
    private function idsDuplicados(DOMXPath $xpath): array
    {
        $vistos = [];
        foreach ($xpath->query('//*[@id][not(ancestor::template)]') as $elemento) {
            $vistos[$elemento->getAttribute('id')] = ($vistos[$elemento->getAttribute('id')] ?? 0) + 1;
        }

        return array_values(array_map(
            fn (string $id) => "id duplicado: {$id}",
            array_keys(array_filter($vistos, fn (int $veces) => $veces > 1)),
        ));
    }

    /** @return list<string> */
    private function referenciasRotas(DOMXPath $xpath): array
    {
        $ids = [];
        foreach ($xpath->query('//*[@id]') as $elemento) {
            $ids[$elemento->getAttribute('id')] = true;
        }

        $problemas = [];
        foreach (['aria-labelledby', 'aria-describedby', 'aria-controls', 'for', 'list', 'form'] as $atributo) {
            foreach ($xpath->query("//*[@{$atributo}]") as $elemento) {
                // Dentro de una plantilla los ids se arman en el navegador.
                if ($xpath->query('ancestor::template', $elemento)->length > 0) {
                    continue;
                }
                foreach (preg_split('~\s+~', trim($elemento->getAttribute($atributo)), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $id) {
                    if (! isset($ids[$id])) {
                        $problemas[] = "{$atributo} apunta a un id que no existe: {$id}";
                    }
                }
            }
        }

        return $problemas;
    }

    /** @return list<string> */
    private function controlesSinNombre(DOMXPath $xpath): array
    {
        $etiquetados = [];
        foreach ($xpath->query('//label') as $etiqueta) {
            foreach (['for', ':for', 'x-bind:for'] as $atributo) {
                if ($etiqueta->hasAttribute($atributo) && $this->tieneTexto($xpath, $etiqueta)) {
                    $etiquetados[$etiqueta->getAttribute($atributo)] = true;
                }
            }
        }

        $problemas = [];
        $controles = '//select | //textarea | //input[not(@type="hidden" or @type="submit" or @type="button" or @type="reset" or @type="image")]';
        foreach ($xpath->query($controles) as $control) {
            $conNombre = $this->tieneNombreAria($control);
            foreach (['id', ':id', 'x-bind:id'] as $atributo) {
                $conNombre = $conNombre || ($control->hasAttribute($atributo) && isset($etiquetados[$control->getAttribute($atributo)]));
            }
            $envoltura = $xpath->query('ancestor::label', $control)->item(0);
            $conNombre = $conNombre || ($envoltura !== null && $this->tieneTexto($xpath, $envoltura));

            if (! $conNombre) {
                $problemas[] = 'control sin etiqueta: '.$this->describir($control);
            }
        }

        return $problemas;
    }

    /** @return list<string> */
    private function botonesYEnlacesSinNombre(DOMXPath $xpath): array
    {
        $problemas = [];
        foreach ($xpath->query('//button | //a[@href or @*[name()=":href"]] | //summary') as $elemento) {
            if (! $this->tieneNombreAria($elemento) && ! $this->tieneTexto($xpath, $elemento)) {
                $problemas[] = 'botón o enlace sin nombre: '.$this->describir($elemento);
            }
        }
        foreach ($xpath->query('//input[@type="submit" or @type="button" or @type="reset"]') as $boton) {
            if (! $this->tieneNombreAria($boton) && trim($boton->getAttribute('value')) === '') {
                $problemas[] = 'botón sin nombre: '.$this->describir($boton);
            }
        }

        return $problemas;
    }

    /** @return list<string> */
    private function tablasEImagenes(DOMXPath $xpath): array
    {
        $problemas = [];
        foreach ($xpath->query('//th[not(@scope)]') as $celda) {
            $problemas[] = 'th sin scope: '.trim($celda->textContent);
        }
        foreach ($xpath->query('//table') as $tabla) {
            if (! $this->tieneNombreAria($tabla) && $xpath->query('./caption', $tabla)->length === 0) {
                $problemas[] = 'tabla sin nombre: '.mb_substr(trim((string) preg_replace('~\s+~', ' ', $tabla->textContent)), 0, 60);
            }
            if ($xpath->query('.//th', $tabla)->length === 0) {
                $problemas[] = 'tabla sin encabezados';
            }
        }
        foreach ($xpath->query('//img[not(@alt) and not(@*[name()=":alt"])]') as $imagen) {
            $problemas[] = 'imagen sin alt: '.$this->describir($imagen);
        }
        foreach ($xpath->query('//*[@role="dialog" or @role="alertdialog" or @role="img" or @role="radiogroup" or @role="tablist"] | //dialog') as $elemento) {
            if (! $this->tieneNombreAria($elemento)) {
                $problemas[] = 'elemento con función sin nombre: '.$this->describir($elemento);
            }
        }

        return $problemas;
    }

    /** @return list<string> */
    private function enviosAutomaticos(DOMXPath $xpath): array
    {
        $problemas = [];
        foreach ($xpath->query('//select[@onchange] | //input[@onchange]') as $control) {
            if (str_contains($control->getAttribute('onchange'), 'ubmit')) {
                $problemas[] = 'envía el formulario al cambiar: '.$this->describir($control);
            }
        }

        return $problemas;
    }

    private function tieneNombreAria(DOMElement $elemento): bool
    {
        foreach (['aria-label', 'aria-labelledby', ':aria-label', 'x-bind:aria-label', ':aria-labelledby'] as $atributo) {
            if (trim($elemento->getAttribute($atributo)) !== '') {
                return true;
            }
        }

        return false;
    }

    /** Texto que aporta nombre: lo escrito, el alt de sus imágenes o lo que pinta Alpine. */
    private function tieneTexto(DOMXPath $xpath, DOMNode $elemento): bool
    {
        $textos = './/text()[not(ancestor::*[@aria-hidden="true"]) and not(ancestor::select) and not(ancestor::textarea)]';
        foreach ($xpath->query($textos, $elemento) as $texto) {
            if (trim($texto->textContent) !== '') {
                return true;
            }
        }

        return $xpath->query('descendant-or-self::*[@x-text or @x-html] | .//img[@alt!="" or @*[name()=":alt"]] | .//*[@aria-label]', $elemento)->length > 0;
    }

    private function describir(DOMElement $elemento): string
    {
        $partes = [$elemento->tagName];
        foreach (['id', 'name', ':name', 'type', 'href', 'class'] as $atributo) {
            if ($elemento->hasAttribute($atributo)) {
                $partes[] = $atributo.'="'.mb_substr($elemento->getAttribute($atributo), 0, 50).'"';
            }
        }

        return '<'.implode(' ', $partes).'>';
    }
}
