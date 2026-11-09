<?php

namespace App\Http\Requests;

use App\Models\SolicitudInsumo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Revisión de la Zona: precios, código, partida presupuestal y proveedor de
 * cada partida (por id, solo las de esta solicitud), más la decisión
 * (guardar, devolver con comentario o aprobar).
 */
class RevisarSolicitudInsumoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $solicitud = $this->route('solicitud');

        return $solicitud instanceof SolicitudInsumo
            && $this->user()?->can('review', $solicitud) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'accion' => ['required', 'in:guardar,devolver,aprobar'],
            'comentario_zona' => ['required_if:accion,devolver', 'nullable', 'string', 'max:5000'],
            'partidas' => ['nullable', 'array'],
            'partidas.*.codigo' => ['nullable', 'string', 'max:50'],
            'partidas.*.precio_unitario' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'partidas.*.partida_presupuestal' => ['nullable', 'string', 'max:10'],
            'partidas.*.proveedor' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Aprobar exige precio y partida presupuestal en todas las partidas.
     */
    public function after(): array
    {
        return [function ($validator) {
            if ($this->input('accion') !== 'aprobar') {
                return;
            }

            $solicitud = $this->route('solicitud');
            $enviadas = $this->input('partidas', []);

            foreach ($solicitud->partidas as $partida) {
                $datos = $enviadas[$partida->id] ?? [];
                $precio = $datos['precio_unitario'] ?? $partida->precio_unitario;
                $presupuestal = $datos['partida_presupuestal'] ?? $partida->partida_presupuestal;

                if ($precio === null || $precio === '' || $presupuestal === null || $presupuestal === '') {
                    $validator->errors()->add(
                        'partidas.'.$partida->id.'.precio_unitario',
                        __('La partida :n necesita precio y partida presupuestal para aprobar.', ['n' => $partida->numero]),
                    );
                }
            }
        }];
    }
}
