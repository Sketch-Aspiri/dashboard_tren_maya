<?php

namespace App\Services\Secciones;

use App\Enums\NivelAccesoEstacion;
use App\Models\Seccion;
use App\Models\SeccionTabla;
use App\Models\User;
use App\Support\AlcanceEstacion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Qué puede ver y editar una cuenta Estación. Los permisos se dan por
 * sección completa (Ver/Editar, para una estación o para todas) y se heredan
 * a sus descendientes visibles; además se pueden dar hoja por hoja. Los
 * permisos solo suman: el nivel de una hoja es el mayor entre el de su
 * sección y el de la hoja. Con permiso sobre una hoja, la estación ve TODAS
 * sus filas (no hay permisos por fila).
 *
 * Los roles de zona no tienen restricción (null / Editar). Se registra como
 * scoped: tres consultas por petición, memoizadas por estación. El memo se
 * ata a la petición HTTP en curso (y se invalida con olvidar() al cambiar
 * permisos); en consola/colas toda la ejecución es una sola "petición", así
 * que quien cambie permisos fuera de AprobacionSeccionesService debe llamar a
 * olvidar().
 */
final class VisibilidadSecciones
{
    /** @var array<int, array{secciones: array<int, NivelAccesoEstacion>, tablas: array<int, NivelAccesoEstacion>, visibles: array<int, true>}> */
    private array $memo = [];

    /** @var array<string, list<int>> "estación:sección" => hojas permitidas (solo para la petición en curso) */
    private array $memoTablas = [];

    private ?object $peticionMemo = null;

    /**
     * @return array<int, true>|null null = sin restricción (roles de zona)
     */
    public function idsVisiblesPara(User $usuario): ?array
    {
        if (AlcanceEstacion::esTotal($usuario)) {
            return null;
        }

        $estacionId = AlcanceEstacion::estacionId($usuario);

        return $estacionId === null ? [] : $this->accesos($estacionId)['visibles'];
    }

    public function esVisiblePara(User $usuario, Seccion $seccion): bool
    {
        $ids = $this->idsVisiblesPara($usuario);

        return $ids === null || isset($ids[$seccion->id]);
    }

    /**
     * Nivel sobre la sección completa (sus documentos y todas sus hojas).
     * null = sin permiso de sección (puede tenerlo solo en alguna hoja).
     */
    public function nivelEnSeccion(User $usuario, Seccion $seccion): ?NivelAccesoEstacion
    {
        if (AlcanceEstacion::esTotal($usuario)) {
            return NivelAccesoEstacion::Editar;
        }

        $estacionId = AlcanceEstacion::estacionId($usuario);

        return $estacionId === null ? null : ($this->accesos($estacionId)['secciones'][$seccion->id] ?? null);
    }

    /**
     * Nivel sobre una hoja: el mayor entre el de su sección y el de la hoja.
     */
    public function nivelEnTabla(User $usuario, SeccionTabla $tabla): ?NivelAccesoEstacion
    {
        if (AlcanceEstacion::esTotal($usuario)) {
            return NivelAccesoEstacion::Editar;
        }

        $estacionId = AlcanceEstacion::estacionId($usuario);

        if ($estacionId === null || $tabla->trashed()) {
            return null;
        }

        $accesos = $this->accesos($estacionId);

        return NivelAccesoEstacion::mayor(
            $accesos['secciones'][$tabla->seccion_id] ?? null,
            $accesos['tablas'][$tabla->id] ?? null,
        );
    }

    /**
     * Ids de las hojas de la sección que el usuario puede abrir, en el orden
     * de la sección. null = todas (zona o permiso sobre la sección completa).
     *
     * @return list<int>|null
     */
    public function tablasVisiblesEn(User $usuario, Seccion $seccion): ?array
    {
        if ($this->nivelEnSeccion($usuario, $seccion) !== null) {
            return null;
        }

        $estacionId = AlcanceEstacion::estacionId($usuario);
        $concedidas = $estacionId === null ? [] : array_keys($this->accesos($estacionId)['tablas']);

        if ($concedidas === []) {
            return [];
        }

        return $this->memoTablas[$estacionId.':'.$seccion->id] ??= $seccion->tablas()->whereIn('id', $concedidas)->pluck('id')->all();
    }

    /**
     * Restricción para consultas de filas/documentos: secciones con permiso
     * completo y hojas sueltas. null = sin restricción (roles de zona).
     *
     * @return array{secciones: list<int>, tablas: list<int>}|null
     */
    public function alcancePara(User $usuario): ?array
    {
        if (AlcanceEstacion::esTotal($usuario)) {
            return null;
        }

        $estacionId = AlcanceEstacion::estacionId($usuario);

        if ($estacionId === null) {
            return ['secciones' => [], 'tablas' => []];
        }

        $accesos = $this->accesos($estacionId);

        return ['secciones' => array_keys($accesos['secciones']), 'tablas' => array_keys($accesos['tablas'])];
    }

    /**
     * Forma pública para los tests y para quien necesite invalidar tras aprobar.
     */
    public function olvidar(): void
    {
        $this->memo = [];
        $this->memoTablas = [];
    }

    /**
     * @return array{secciones: array<int, NivelAccesoEstacion>, tablas: array<int, NivelAccesoEstacion>, visibles: array<int, true>}
     */
    private function accesos(int $estacionId): array
    {
        // El memo solo vale para la petición en curso: otra petición (o un test) lo recalcula.
        $peticion = request();

        if ($this->peticionMemo !== $peticion) {
            $this->olvidar();
            $this->peticionMemo = $peticion;
        }

        if (isset($this->memo[$estacionId])) {
            return $this->memo[$estacionId];
        }

        $secciones = Seccion::query()->get(['id', 'parent_id', 'is_visible', 'acceso_todas']);
        $niveles = $this->nivelesDeSecciones($secciones, $estacionId);
        $tablas = $this->nivelesDeTablas($secciones->where('is_visible', true)->pluck('id')->flip()->all(), $estacionId);

        $visibles = array_fill_keys(array_keys($niveles), true);

        foreach ($tablas as $tabla) {
            $visibles[$tabla['seccion_id']] = true;
        }

        return $this->memo[$estacionId] = [
            'secciones' => $niveles,
            'tablas' => array_map(fn (array $tabla) => $tabla['nivel'], $tablas),
            'visibles' => $visibles,
        ];
    }

    /**
     * Nivel de sección de cada sección alcanzable: el propio (aprobación de la
     * estación o "todas") o el heredado de un ancestro, el mayor. Una sección
     * oculta no se abre y corta la herencia hacia lo que cuelga de ella.
     *
     * @param  Collection<int, Seccion>  $secciones
     * @return array<int, NivelAccesoEstacion>
     */
    private function nivelesDeSecciones($secciones, int $estacionId): array
    {
        $aprobadas = DB::table('seccion_estacion')->where('estacion_id', $estacionId)->pluck('nivel', 'seccion_id');
        $hijasDe = $secciones->groupBy(fn (Seccion $s) => $s->parent_id ?? 0);
        $pendientes = [];

        foreach ($secciones as $seccion) {
            $propio = NivelAccesoEstacion::mayor(
                $seccion->acceso_todas,
                NivelAccesoEstacion::tryFrom((string) ($aprobadas[$seccion->id] ?? '')),
            );

            if ($seccion->is_visible && $propio !== null) {
                $pendientes[] = [$seccion->id, $propio];
            }
        }

        $niveles = [];

        while ($pendientes !== []) {
            [$id, $nivel] = array_pop($pendientes);
            $actual = $niveles[$id] ?? null;
            $nuevo = NivelAccesoEstacion::mayor($actual, $nivel);

            if ($nuevo === $actual) {
                continue;
            }

            $niveles[$id] = $nuevo;

            foreach ($hijasDe->get($id, collect()) as $hija) {
                if ($hija->is_visible) {
                    $pendientes[] = [$hija->id, $nuevo];
                }
            }
        }

        return $niveles;
    }

    /**
     * Permisos por hoja de la estación (hojas activas de secciones visibles).
     *
     * @param  array<int, int>  $seccionesVisibles  id => cualquier valor
     * @return array<int, array{seccion_id: int, nivel: NivelAccesoEstacion}>
     */
    private function nivelesDeTablas(array $seccionesVisibles, int $estacionId): array
    {
        $tablas = [];

        $filas = DB::table('seccion_tabla_estacion')
            ->join('seccion_tablas', 'seccion_tablas.id', '=', 'seccion_tabla_estacion.seccion_tabla_id')
            ->where('seccion_tabla_estacion.estacion_id', $estacionId)
            ->whereNull('seccion_tablas.deleted_at')
            ->get(['seccion_tablas.id', 'seccion_tablas.seccion_id', 'seccion_tabla_estacion.nivel']);

        foreach ($filas as $fila) {
            $nivel = NivelAccesoEstacion::tryFrom((string) $fila->nivel);

            if ($nivel !== null && isset($seccionesVisibles[(int) $fila->seccion_id])) {
                $tablas[(int) $fila->id] = ['seccion_id' => (int) $fila->seccion_id, 'nivel' => $nivel];
            }
        }

        return $tablas;
    }
}
