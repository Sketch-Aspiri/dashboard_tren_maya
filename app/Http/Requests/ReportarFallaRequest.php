<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET /ecosistema/reportar?desde=`: la página desde la que se reporta la
 * falla. Solo una ruta relativa de este sitio, sin consulta ni fragmento (no
 * se copian filtros ni datos de la URL al ticket); cualquier otra cosa se
 * reemplaza por "/" en lugar de fallar.
 */
class ReportarFallaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [];
    }

    public function desde(): string
    {
        $desde = $this->query('desde');

        return is_string($desde) && preg_match('#^/(?!/)[A-Za-z0-9/_.-]{0,300}$#D', $desde) === 1 ? $desde : '/';
    }
}
