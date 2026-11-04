<?php

namespace App\Policies;

use App\Enums\NivelAccesoEstacion;
use App\Models\Seccion;
use App\Models\SeccionArchivo;
use App\Models\SeccionFila;
use App\Models\SeccionTabla;
use App\Models\User;
use App\Services\Secciones\VisibilidadSecciones;
use App\Support\Roles;
use Illuminate\Auth\Access\Response;

/**
 * Secciones dinámicas (tabla + documentos).
 *
 * - Roles de zona (Jefe, Administrador, Edificio): ven y editan todo, datos y
 *   estructura. Solo Jefe/Administrador dan permisos a las estaciones.
 * - Estación: permisos por sección completa o por hoja, nivel Ver o Editar
 *   (ver VisibilidadSecciones). Con Ver consulta la hoja/sección completa;
 *   con Editar además agrega, modifica y borra filas, y (a nivel sección)
 *   sube y borra documentos. No hay permisos por fila. Lo que no le
 *   corresponde responde 404 (no 403), para no revelar que existe; lo que ve
 *   pero no puede editar, 403. No toca la estructura ni importa datos.
 *
 * Qué hoja se puede abrir lo restringe ConTablaDeSeccion (una hoja no
 * permitida no pasa la validación, igual que una inexistente).
 *
 * Habilidades: view / gestionarDatos (importar, deshacer, IA,
 * nube, enlaces, pestaña Cargas) / create-update-delete (menú, columnas,
 * tablas) y las de captura (crearFila, actualizarFila, eliminarFila,
 * verArchivo, subirDocumentos, eliminarArchivo).
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

        return $this->visibilidad->esVisiblePara($user, $seccion) ? Response::allow() : Response::denyAsNotFound();
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
     * (Re)vincular a mano un documento con la nube. Solo roles de zona; un
     * documento espejo de la nube lo administra su origen.
     */
    public function administrarArchivo(User $user, Seccion $seccion, SeccionArchivo $archivo): Response
    {
        if (! $this->esZona($user)) {
            return Response::deny();
        }

        return $this->soloSiNoEsEspejo($archivo);
    }

    /**
     * Borrar un documento: la zona, o una estación con Editar sobre la
     * sección (o sobre la hoja, si es adjunto de una fila). Un documento
     * espejo de la nube no se borra a mano: se desvincula el origen.
     */
    public function eliminarArchivo(User $user, Seccion $seccion, SeccionArchivo $archivo): Response
    {
        $permiso = $this->segunNivel($user, $this->nivelArchivo($user, $seccion, $archivo));

        if (! $permiso->allowed()) {
            return $permiso;
        }

        // Un documento con origen de nube (aunque se haya vinculado a mano) lo administra la zona.
        if (! $this->esZona($user) && $archivo->seccion_origen_id !== null) {
            return Response::deny();
        }

        return $this->soloSiNoEsEspejo($archivo);
    }

    /**
     * Asignar la estación de filas y documentos (dato informativo: no da
     * permisos). Solo roles de zona.
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
     * Dar permisos a estaciones (sección u hoja): solo Jefe de Zona / Administrador.
     */
    public function aprobarEstaciones(User $user, Seccion $seccion): bool
    {
        return $user->hasAnyRole(Roles::JEFATURA);
    }

    /**
     * Estructura de una tabla (columnas, baja de la hoja, cargas manuales):
     * una tabla espejo de la nube es de solo lectura para todos.
     */
    public function modificarTabla(User $user, Seccion $seccion, SeccionTabla $tabla): Response
    {
        if (! $this->esZona($user)) {
            return Response::deny();
        }

        return $this->soloSiEscribible($tabla);
    }

    /**
     * $tabla = la hoja donde se agregaría la fila. Una estación sin hoja
     * conocida no agrega nada (falla cerrada).
     */
    public function crearFila(User $user, Seccion $seccion, ?SeccionTabla $tabla = null): Response
    {
        if ($this->esZona($user)) {
            return $this->soloSiEscribible($tabla);
        }

        $permiso = $this->segunNivel($user, $this->nivelTabla($user, $seccion, $tabla));

        if (! $permiso->allowed()) {
            return $permiso;
        }

        // Una sección "solo documentos" no tiene tabla.
        return $seccion->solo_documentos ? Response::deny() : $this->soloSiEscribible($tabla);
    }

    /**
     * Editar la hoja de cálculo: celdas, formato, insertar/borrar filas,
     * combinar y ordenar. Mismo permiso que agregar filas a esa hoja (zona,
     * o estación con Editar sobre la hoja o la sección; nunca una espejo).
     * Las columnas y las hojas son estructura: modificarTabla / update.
     */
    public function editarHoja(User $user, Seccion $seccion, SeccionTabla $tabla): Response
    {
        return $this->crearFila($user, $seccion, $tabla);
    }

    public function actualizarFila(User $user, Seccion $seccion, SeccionFila $fila): Response
    {
        return $this->editarFila($user, $seccion, $fila);
    }

    public function eliminarFila(User $user, Seccion $seccion, SeccionFila $fila): Response
    {
        return $this->editarFila($user, $seccion, $fila);
    }

    public function verArchivo(User $user, Seccion $seccion, SeccionArchivo $archivo): Response
    {
        return $this->segunNivel($user, $this->nivelArchivo($user, $seccion, $archivo), soloVer: true);
    }

    /**
     * Subir documentos a la sección (Editar sobre la sección completa) o
     * adjuntarlos a una fila (Editar sobre su hoja).
     */
    public function subirDocumentos(User $user, Seccion $seccion, ?SeccionFila $fila = null): Response
    {
        $nivel = $fila === null
            ? $this->visibilidad->nivelEnSeccion($user, $seccion)
            : $this->nivelTabla($user, $seccion, $fila->tabla);
        $permiso = $this->segunNivel($user, $nivel);

        // Una estación no adjunta documentos a filas de una tabla espejo (de solo lectura).
        return $permiso->allowed() && $fila !== null && ! $this->esZona($user) ? $this->soloSiEscribible($fila->tabla) : $permiso;
    }

    private function editarFila(User $user, Seccion $seccion, SeccionFila $fila): Response
    {
        $permiso = $this->segunNivel($user, $this->nivelTabla($user, $seccion, $fila->tabla));

        return $permiso->allowed() ? $this->soloSiEscribible($fila->tabla) : $permiso;
    }

    /**
     * Sin nivel: 404 para una estación (no revela que existe), 403 para
     * cualquier otra cuenta. Con Ver: permitido si solo se pide ver; si se
     * pide editar, 403. Con Editar: permitido.
     */
    private function segunNivel(User $user, ?NivelAccesoEstacion $nivel, bool $soloVer = false): Response
    {
        if ($nivel === null) {
            return $user->hasRole(Roles::ESTACION) || $this->esZona($user) ? Response::denyAsNotFound() : Response::deny();
        }

        return $soloVer || $nivel->permiteEditar() ? Response::allow() : Response::deny();
    }

    private function nivelTabla(User $user, Seccion $seccion, ?SeccionTabla $tabla): ?NivelAccesoEstacion
    {
        // Una hoja de otra sección nunca hereda el permiso de ésta.
        if ($tabla === null || (int) $tabla->seccion_id !== (int) $seccion->id) {
            return null;
        }

        return $this->visibilidad->nivelEnTabla($user, $tabla);
    }

    /**
     * Un documento suelto sigue a la sección; un adjunto de fila, además, a
     * la hoja de su fila (el mayor de los dos).
     */
    private function nivelArchivo(User $user, Seccion $seccion, SeccionArchivo $archivo): ?NivelAccesoEstacion
    {
        if ((int) $archivo->seccion_id !== (int) $seccion->id) {
            return null;
        }

        $nivel = $this->visibilidad->nivelEnSeccion($user, $seccion);

        // Ya con Editar no hace falta mirar la fila (evita cargarla por documento en la lista).
        if ($nivel?->permiteEditar()) {
            return $nivel;
        }

        $fila = $archivo->seccion_fila_id === null ? null : $archivo->fila;

        return $fila === null ? $nivel : NivelAccesoEstacion::mayor($nivel, $this->nivelTabla($user, $seccion, $fila->tabla));
    }

    private function soloSiEscribible(?SeccionTabla $tabla): Response
    {
        return $tabla?->esEspejo()
            ? Response::deny(__('Esta tabla se sincroniza con la nube y es de solo lectura. Cambia el Excel en la nube o desvincula el origen.'))
            : Response::allow();
    }

    private function soloSiNoEsEspejo(SeccionArchivo $archivo): Response
    {
        return $archivo->esEspejo()
            ? Response::deny(__('Este documento viene de una carpeta de la nube: desvincula el origen para quitarlo.'))
            : Response::allow();
    }

    private function esZona(User $user): bool
    {
        return $user->hasAnyRole(Roles::ZONA);
    }
}
