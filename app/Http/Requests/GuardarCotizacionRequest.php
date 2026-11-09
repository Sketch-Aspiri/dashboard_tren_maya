<?php

namespace App\Http\Requests;

use App\Models\Cotizacion;
use App\Models\SolicitudInsumo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Alta o edición de una cotización (Jefe de Zona, Administrador y Edificio):
 * datos del encabezado, PDF opcional y renglones vinculados con partidas del
 * mismo mes. La cotización a editar llega por la ruta, nunca por el cuerpo.
 */
class GuardarCotizacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', Cotizacion::class) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $actual = $this->route('cotizacion');

        return [
            'anio' => ['required', 'integer', 'min:2000', 'max:2100'],
            'mes' => ['required', 'integer', 'min:1', 'max:12'],
            'proveedor' => ['required', 'string', 'max:150'],
            'folio' => ['required', 'string', 'max:50', Rule::unique('cotizaciones', 'folio')->where('proveedor', trim((string) $this->input('proveedor')))->ignore($actual?->id)],
            'fecha' => ['nullable', 'date'],
            'subtotal' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'iva' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'total' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'archivo' => ['nullable', 'file', 'extensions:pdf', 'mimes:pdf', 'max:10240'],
            'leida_con_ia' => ['nullable', 'boolean'],
            'renglones' => ['required', 'array', 'min:1', 'max:200'],
            'renglones.*.partida_id' => ['nullable', 'integer', 'exists:solicitud_insumo_partidas,id'],
            'renglones.*.clave' => ['nullable', 'string', 'max:50'],
            'renglones.*.descripcion' => ['required', 'string', 'max:255'],
            'renglones.*.cantidad' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'renglones.*.precio_unitario' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'renglones.*.importe' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'renglones.*.coincidencia' => ['nullable', Rule::in(['mismo', 'otra_marca', 'distinto'])],
        ];
    }

    /**
     * Una partida solo se puede cotizar si es de una solicitud aprobada del
     * mismo mes que la cotización.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validador) {
            if ($validador->errors()->isNotEmpty()) {
                return;
            }

            $ids = collect($this->input('renglones', []))->pluck('partida_id')->filter()->unique()->all();

            if ($ids === []) {
                return;
            }

            $validas = SolicitudInsumo::query()
                ->where('anio', (int) $this->input('anio'))->where('mes', (int) $this->input('mes'))
                ->whereIn('estado', SolicitudInsumo::ESTADOS_APROBADOS)
                ->join('solicitud_insumo_partidas as p', 'p.solicitud_insumo_id', '=', 'solicitudes_insumos.id')
                ->whereIn('p.id', $ids)->where('p.eliminada_por_coordinacion', false)
                ->pluck('p.id')->all();

            foreach ($this->input('renglones', []) as $i => $fila) {
                if (! empty($fila['partida_id']) && ! in_array((int) $fila['partida_id'], $validas, true)) {
                    $validador->errors()->add('renglones.'.$i.'.partida_id', __('La partida elegida no es de una solicitud aprobada de este mes.'));
                }
            }
        }];
    }
}
