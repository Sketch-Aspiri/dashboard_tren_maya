<?php

namespace App\Http\Requests\Secciones;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Permisos de las estaciones sobre una hoja ("estaciones[<id>]" =
 * ver|editar, vacío = sin permiso propio de la hoja). Igual que la sección,
 * sin la opción "todas".
 */
class CompartirTablaRequest extends CompartirSeccionRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $reglas = parent::rules();
        unset($reglas['todas']);

        return $reglas;
    }
}
