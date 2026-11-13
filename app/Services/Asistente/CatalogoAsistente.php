<?php

namespace App\Services\Asistente;

use App\Enums\EmpleadoEstatus;
use App\Models\Estacion;
use App\Models\MenuGrupo;
use App\Models\Seccion;
use App\Models\SeccionColumna;
use App\Models\SeccionTabla;
use App\Support\DatosSensibles;
use Carbon\CarbonImmutable;

/**
 * Fecha y catálogo vigente de secciones, columnas y estaciones: lo que necesita quien consulta con
 * `consultar_seccion` para saber qué slugs y columnas existen. Lo usan el asistente del Dashboard y las herramientas
 * para IA del puente. Sin usuario ni sesión; las columnas sensibles nunca aparecen.
 */
final class CatalogoAsistente
{
    private const MAX_COLUMNAS_CATALOGO = 30;

    /** Con varias tablas por sección el catálogo recorta más para no crecer sin tope. */
    private const MAX_COLUMNAS_CATALOGO_MULTI = 12;

    public function texto(): string
    {
        $grupos = MenuGrupo::query()->orderBy('orden')->with(['secciones' => fn ($q) => $q
            ->with(['tablas' => fn ($t) => $t->with('columnas')->withCount('filas')])
            ->withCount(['filas', 'archivos'])])->get();
        $lineas = [];

        foreach ($grupos as $grupo) {
            foreach ($grupo->secciones as $seccion) {
                $lineas[] = $this->lineaCatalogo($grupo->nombre, $seccion);
            }
        }

        // «Hoy» en la zona horaria del negocio (Cancún), no en la del servidor (UTC): igual que la captura de asistencia.
        $hoy = CarbonImmutable::parse(CarbonImmutable::now((string) config('ecosistema.ia.zona_horaria'))->toDateString());

        return implode("\n", [
            'Fecha de hoy: '.$hoy->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY').' ('.$hoy->toDateString().').',
            'Estaciones: '.Estacion::query()->orderBy('orden')->pluck('nombre')->implode(', ').'.',
            'Estatus de Personal: '.implode(', ', array_column(EmpleadoEstatus::cases(), 'value')).'.',
            '',
            'Catálogo de secciones (slug | grupo > nombre | registros, documentos | columnas: nombre [tipo]):',
            ...$lineas,
        ]);
    }

    private function lineaCatalogo(string $grupo, Seccion $seccion): string
    {
        $encabezado = "- {$seccion->slug} | {$grupo} > {$seccion->nombre} | {$seccion->filas_count} reg., {$seccion->archivos_count} doc. | ";

        // Una sola tabla: sin ceremonia, como siempre.
        if ($seccion->tablas->count() <= 1) {
            return $encabezado.($this->columnasCatalogo($seccion->tablas->first(), self::MAX_COLUMNAS_CATALOGO) ?: 'sin columnas');
        }

        $tablas = $seccion->tablas->map(fn (SeccionTabla $tabla) => "[{$tabla->nombre}] ({$tabla->filas_count} reg.): ".($this->columnasCatalogo($tabla, self::MAX_COLUMNAS_CATALOGO_MULTI) ?: 'sin columnas'));

        return $encabezado.'varias tablas, indica "tabla" al consultar → '.$tablas->implode(' / ');
    }

    private function columnasCatalogo(?SeccionTabla $tabla, int $maximo): string
    {
        return $tabla === null ? '' : $tabla->columnas
            ->reject(fn (SeccionColumna $columna) => DatosSensibles::esColumnaSensible($columna->nombre))
            ->take($maximo)
            ->map(fn (SeccionColumna $columna) => "{$columna->nombre} [{$columna->tipo->value}]")
            ->implode('; ');
    }
}
