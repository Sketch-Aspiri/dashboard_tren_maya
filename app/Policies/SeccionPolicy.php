<?php

namespace App\Policies;

use App\Models\Seccion;
use App\Models\SeccionArchivo;
use App\Models\SeccionFila;
use App\Models\User;
use App\Services\Secciones\VisibilidadSecciones;
use App\Support\Roles;
use Illuminate\Auth\Access\Response;

/**
 * Secciones dinámicas (tabla + documentos).
 *
 * - Roles de zona (Jefe, Administrador, Edificio): ven y editan todo, datos y
 *   estructura. Solo Jefe/Administrador aprueban secciones para estaciones.
 * - Estación: solo las secciones aprobadas para su estación (y sus
 *   descendientes), y dentro de ellas solo las filas/documentos etiquetados con
 *   su estación. Lo que no le corresponde responde 404 (no 403), para no
 *   revelar que existe. No toca la estructura ni importa/borra datos.
 *
 * Habilidades: view / gestionarDatos (importar, deshacer, IA, nube, enlaces,
 * borrar documentos, pestaña Cargas) / create-update-delete (menú, columnas,
 * tablas) y las de captura de una estación (crearFila, actualizarFila,
 * eliminarFila, verArchivo, subirDocumentos).
 */
class SeccionPolicy
{
    public function __construct(private readonly VisibilidadSecciones $visibilidad) {}

    public function viewAny(User $user): bool
    {
        return $this->esZona($user) || $user->hasRole(Roles::ESTACION);
    }

    public function view(User $user, Seccion $seccion): Response
    {
        if ($this->esZona($user)) {
            return Response::allow();
        }

        // Sin rol de zona ni de estación no hay nada que ocultar: 403. A una estación, lo no aprobado le sale 404.
        if (! $user->hasRole(Roles::ESTACION)) {
            return Response::deny();
        }

        return $this->visibleParaEstacion($user, $seccion) ? Response::allow() : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return $this->esZona($user);
    }

    public function update(User $user, Seccion $seccion): bool
    {
        return $this->esZona($user);
    }

    public function delete(User $user, Seccion $seccion): bool
    {
        return $this->esZona($user);
    }

    public function gestionarDatos(User $user, Seccion $seccion): bool
    {
        return $this->esZona($user);
    }

    /**
     * Borrar o (re)vincular a mano un documento. Un documento espejo de la
     * nube lo administra su origen: solo se puede desvincular el origen.
     */
    public function administrarArchivo(User $user, Seccion $seccion, SeccionArchivo $archivo): Response
    {
        if (! $this->esZona($user)) {
            return Response::deny();
        }

        return $archivo->esEspejo()
            ? Response::deny(__('Este documento viene de una carpeta de la nube: desvincula el origen para quitarlo.'))
            : Response::allow();
    }

    /**
     * Asignar la estación de filas y documentos (individual o en bloque por
     * columna). Solo roles de zona.
     */
    public function etiquetarEstacion(User $user, Seccion $seccion): bool
    {
        return $this->esZona($user);
    }

    public function verEnlaces(User $user, Seccion $seccion): bool
    {
        return $this->esZona($user);
    }

    /**
     * Aprobar una sección para estaciones: solo Jefe de Zona / Administrador.
     */
    public function aprobarEstaciones(User $user, Seccion $seccion): bool
    {
        return $user->hasAnyRole(Roles::JEFATURA);
    }

    public function crearFila(User $user, Seccion $seccion): Response
    {
        if ($this->esZona($user)) {
            return Response::allow();
        }

        if (! $this->visibleParaEstacion($user, $seccion)) {
            return Response::denyAsNotFound();
        }

        // Una sección "solo documentos" no tiene tabla; y sin estación no hay a quién etiquetar.
        return ! $seccion->solo_documentos && $user->estacion_id !== null ? Response::allow() : Response::deny();
    }

    public function actualizarFila(User $user, Seccion $seccion, SeccionFila $fila): Response
    {
        if ($this->esZona($user)) {
            return Response::allow();
        }

        return $this->visibleParaEstacion($user, $seccion) && $this->esPropia($user, $fila->estacion_id)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function eliminarFila(User $user, Seccion $seccion, SeccionFila $fila): Response
    {
        if ($this->esZona($user)) {
            return Response::allow();
        }

        // Una fila propia existe para la estación: 403. Cualquier otra: 404.
        return $this->visibleParaEstacion($user, $seccion) && $this->esPropia($user, $fila->estacion_id)
            ? Response::deny()
            : Response::denyAsNotFound();
    }

    public function verArchivo(User $user, Seccion $seccion, SeccionArchivo $archivo): Response
    {
        if ($this->esZona($user)) {
            return Response::allow();
        }

        return $this->visibleParaEstacion($user, $seccion) && $this->esPropia($user, $archivo->estacion_id)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function subirDocumentos(User $user, Seccion $seccion): Response
    {
        if ($this->esZona($user)) {
            return Response::allow();
        }

        return $this->visibleParaEstacion($user, $seccion) && $user->estacion_id !== null
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    private function esZona(User $user): bool
    {
        return $user->hasAnyRole(Roles::ZONA);
    }

    private function visibleParaEstacion(User $user, Seccion $seccion): bool
    {
        return $user->hasRole(Roles::ESTACION) && $this->visibilidad->esVisiblePara($user, $seccion);
    }

    private function esPropia(User $user, ?int $estacionId): bool
    {
        return $estacionId !== null && $estacionId === $user->estacion_id;
    }
}
