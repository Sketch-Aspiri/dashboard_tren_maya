<?php

namespace App\Http\Requests\Secciones;

use App\Models\SeccionMapeo;
use App\Models\SeccionOrigen;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;

/**
 * Fila de encabezados y columna llave de cada hoja mapeada del origen.
 */
class ActualizarMapeoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gestionarDatos', $this->route('seccion')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'mapeos' => ['required', 'array', 'max:50'],
            'mapeos.*.fila_encabezado' => ['required', 'integer', 'min:0', 'max:200'],
            'mapeos.*.llave_clave' => ['nullable', 'string', 'max:100'],
            'confirmar_masivo' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function ($validator): void {
            $propios = $this->origen()->mapeos()->pluck('id')->all();

            foreach (array_keys((array) $this->input('mapeos', [])) as $id) {
                if (! in_array((int) $id, $propios, true)) {
                    $validator->errors()->add('mapeos', __('Una de las hojas no pertenece a este origen.'));
                }
            }
        }];
    }

    /**
     * @return Collection<int, array{mapeo: SeccionMapeo, fila_encabezado: int, llave_clave: ?string}>
     */
    public function cambios(): Collection
    {
        $mapeos = $this->origen()->mapeos()->with('origen.seccion', 'tabla.seccion', 'archivo')->get()->keyBy('id');

        return collect($this->validated('mapeos'))->map(fn (array $datos, int|string $id): array => [
            'mapeo' => $mapeos[(int) $id],
            'fila_encabezado' => (int) $datos['fila_encabezado'],
            'llave_clave' => ($datos['llave_clave'] ?? '') === '' ? null : $datos['llave_clave'],
        ])->values();
    }

    private function origen(): SeccionOrigen
    {
        return $this->route('origen');
    }
}
