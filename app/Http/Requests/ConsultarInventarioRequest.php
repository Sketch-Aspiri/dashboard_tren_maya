<?php

namespace App\Http\Requests;

use App\Models\Estacion;
use App\Models\InventarioEstacion;
use App\Models\SolicitudInsumo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Consulta del inventario de una estación (?estacion=&tipo=&q=) y la
 * verificación de un pedido contra ese inventario (?estacion=&descripcion=).
 * Zona ve cualquier estación; una estación solo la suya.
 */
class ConsultarInventarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        $usuario = $this->user();

        if ($usuario === null || ! $usuario->can('viewAny', SolicitudInsumo::class)) {
            return false;
        }

        $estacionId = $this->input('estacion');

        return $estacionId === null || $estacionId === '' || $usuario->can('manageFor', [SolicitudInsumo::class, Estacion::query()->find($estacionId) ?? new Estacion]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'estacion' => ['nullable', 'integer', 'exists:estaciones,id'],
            'tipo' => ['nullable', Rule::in([InventarioEstacion::HERRAMIENTA, InventarioEstacion::EPP, InventarioEstacion::CONSUMIBLE])],
            'q' => ['nullable', 'string', 'max:100'],
            'descripcion' => ['nullable', 'string', 'max:255'],
        ];
    }
}
