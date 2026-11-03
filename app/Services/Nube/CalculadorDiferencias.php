<?php

namespace App\Services\Nube;

use App\Models\SeccionArchivo;
use App\Services\Nube\Datos\ArchivoRemoto;
use App\Services\Nube\Datos\Diferencias;

/**
 * Servicio puro: compara lo que lista la nube contra los documentos del
 * origen por `remoto_id`. No toca la base de datos ni la red.
 *
 * - alta: remoto sin documento.
 * - cambio de contenido: `remoto_version` distinta (o desconocida: el motor
 *   descarga y compara el hash).
 * - renombre/mueve: cambió el nombre o la ruta, sin descargar.
 * - baja: documento vivo que ya no aparece (se archiva).
 * - restauración: documento archivado que reaparece.
 * - cambio masivo retenido: el listado vino vacío pero había documentos; no
 *   se archiva nada hasta que se confirme.
 */
final class CalculadorDiferencias
{
    /**
     * @param  list<ArchivoRemoto>  $remotos
     * @param  iterable<SeccionArchivo>  $locales  incluye los archivados (con papelera)
     */
    public function calcular(array $remotos, iterable $locales, bool $bajaMasivaConfirmada = false): Diferencias
    {
        $porRemotoId = [];
        $vivos = 0;

        foreach ($locales as $local) {
            $porRemotoId[$local->remoto_id] = $local;
            $vivos += $local->trashed() ? 0 : 1;
        }

        $altas = [];
        $actualizar = [];
        $sinCambios = 0;
        $vistos = [];

        foreach ($remotos as $remoto) {
            $vistos[$remoto->id] = true;
            $local = $porRemotoId[$remoto->id] ?? null;

            if ($local === null) {
                $altas[] = $remoto;

                continue;
            }

            $cambio = [
                'archivo' => $local,
                'remoto' => $remoto,
                'contenido' => $remoto->version === null || $remoto->version !== $local->remoto_version,
                'renombre' => $remoto->nombre !== $local->nombre_original || $remoto->rutaRelativa !== $local->remoto_ruta,
                'restaurar' => $local->trashed(),
            ];

            if ($cambio['contenido'] || $cambio['renombre'] || $cambio['restaurar']) {
                $actualizar[] = $cambio;
            } else {
                $sinCambios++;
            }
        }

        $bajas = [];

        foreach ($porRemotoId as $remotoId => $local) {
            if (! $local->trashed() && ! isset($vistos[$remotoId])) {
                $bajas[] = $local;
            }
        }

        $sospechoso = $remotos === [] && $vivos > 0 && ! $bajaMasivaConfirmada;

        return new Diferencias($altas, $actualizar, $sospechoso ? [] : $bajas, $sinCambios, $sospechoso);
    }
}
