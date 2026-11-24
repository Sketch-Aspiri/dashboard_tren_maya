<?php

namespace App\Policies;

use App\Models\AguaRegistro;
use App\Models\Estacion;
use App\Models\User;
use App\Support\Roles;

/**
 * Registro de agua. "Estación" captura las compras de la suya (y Edificio las de "Edificio Zonal Este")
 * solo en borrador u observada; la Zona (Jefe de Zona, Administrador y Edificio) ve todas, fija el programa
 * del mes, revisa, aprueba y descarga el Excel.
 */
class AguaRegistroPolicy
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

    public function view(User $user, AguaRegistro $registro): bool
    {
        return $this->manageFor($user, $registro->estacion);
    }

    /** Captura de compras y envío: la estación dueña (o Edificio en la suya), mientras sea editable. */
    public function update(User $user, AguaRegistro $registro): bool
    {
        $propia = $user->estacionPropiaId();

        return $propia !== null
            && $propia === $registro->estacion_id
            && $registro->esEditablePorEstacion();
    }

    public function review(User $user, AguaRegistro $registro): bool
    {
        return $user->hasAnyRole(Roles::ZONA) && $registro->estado === AguaRegistro::ENVIADA;
    }

    /** Programa del mes (frecuencia, consumo, costo, referencia…) y Excel consolidado: solo la Zona. */
    public function gestionar(User $user): bool
    {
        return $user->hasAnyRole(Roles::ZONA);
    }
}
