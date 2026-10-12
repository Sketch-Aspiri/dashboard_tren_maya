<?php

namespace App\Http\Requests\Secciones;

use App\Models\MenuGrupo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Alta (POST) y cambio de nombre (PUT) de un grupo del menú.
 */
class GuardarMenuGrupoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $grupo = $this->route('grupo');

        return $grupo instanceof MenuGrupo
            ? $this->user()?->can('update', $grupo) ?? false
            : $this->user()?->can('create', MenuGrupo::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:120'],
        ];
    }
}
