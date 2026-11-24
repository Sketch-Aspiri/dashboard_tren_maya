<?php

namespace App\Policies;

use App\Models\Estacion;
use App\Models\PtarRegistro;
use App\Models\User;
use App\Support\Roles;

/**
 * PTAR. "Estación" captura el registro de la suya (y Edificio el de "Edificio Zonal Este") solo en borrador u
 * observada; la Zona (Jefe de Zona, Administrador y Edificio) ve todas, revisa, aprueba, gestiona los hallazgos
 * y descarga el Excel.
 */
class PtarRegistroPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([...Roles::ZONA, Roles::ESTACION]);
    }

    public function manageFor(User $user, Estacion $estacion): bool
    {
        return $user->hasAnyRole(Roles::ZONA)
            || ($user->hasRole(Roles::ESTACION) && $user->estacion_id === $estacion->id);
    }

    public function view(User $user, PtarRegistro $registro): bool
    {
        return $this->manageFor($user, $registro->estacion);
    }

    /** Captura y envío: la estación dueña (o Edificio en la suya), mientras sea editable. */
    public function update(User $user, PtarRegistro $registro): bool
    {
        $propia = $this->estacionPropiaId($user);

        return $propia !== null
            && $propia === $registro->estacion_id
            && $registro->esEditablePorEstacion();
    }

    public function review(User $user, PtarRegistro $registro): bool
    {
        return $user->hasAnyRole(Roles::ZONA) && $registro->estado === PtarRegistro::ENVIADA;
    }

    /** Hallazgos, Excel consolidado y oficio: solo la Zona. */
    public function gestionar(User $user): bool
    {
        return $user->hasAnyRole(Roles::ZONA);
    }

    /** Estación a nombre de la que captura la cuenta: la suya si es Estación, "Edificio Zonal Este" si es Edificio. */
    private function estacionPropiaId(User $user): ?int
    {
        if ($user->hasRole(Roles::ESTACION)) {
            return $user->estacion_id;
        }

        return $user->hasRole(Roles::EDIFICIO) ? Estacion::query()->where('nombre', 'Edificio Zonal Este')->value('id') : null;
    }
}
