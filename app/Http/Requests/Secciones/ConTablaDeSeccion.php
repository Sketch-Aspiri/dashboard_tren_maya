<?php

namespace App\Http\Requests\Secciones;

use App\Models\SeccionTabla;
use App\Services\Secciones\VisibilidadSecciones;
use Illuminate\Validation\Rule;

/**
 * Elección de la tabla de una sección con ?tabla=<id> (o un campo oculto
 * "tabla" en los formularios). Si no viene se usa la primera; un id de otra
 * sección o dado de baja no pasa la validación. Una estación con permiso
 * solo sobre algunas hojas solo puede elegir esas (y por defecto, la
 * primera de ellas): las demás no pasan la validación, igual que una
 * inexistente.
 */
trait ConTablaDeSeccion
{
    private ?SeccionTabla $tablaElegida = null;

    /** @var list<int>|null|false false = aún sin calcular */
    private array|null|false $tablasPermitidas = false;

    /**
     * @return array<string, array<mixed>>
     */
    protected function reglaTabla(): array
    {
        $permitidas = $this->tablasPermitidas();

        return [
            'tabla' => [
                'nullable',
                'integer',
                Rule::exists('seccion_tablas', 'id')
                    ->where('seccion_id', $this->route('seccion')->id)
                    ->whereNull('deleted_at')
                    ->when($permitidas !== null, fn ($regla) => $regla->whereIn('id', $permitidas)),
            ],
        ];
    }

    public function tabla(): SeccionTabla
    {
        if ($this->tablaElegida !== null) {
            return $this->tablaElegida;
        }

        $id = $this->input('tabla');
        $id = is_numeric($id) ? (int) $id : null;
        $permitidas = $this->tablasPermitidas();

        if ($permitidas !== null && $id === null) {
            // Sin hoja pedida, la primera permitida (nunca la primera de la sección); sin
            // ninguna permitida no hay tabla que mostrar. Una hoja pedida y no permitida
            // se resuelve tal cual: la validación y la Policy (404) la rechazan.
            abort_if($permitidas === [], 404);
            $id = $permitidas[0];
        }

        return $this->tablaElegida = $this->route('seccion')->tablaActiva($id);
    }

    /**
     * Hojas que el usuario puede abrir; null = todas.
     *
     * @return list<int>|null
     */
    private function tablasPermitidas(): ?array
    {
        if ($this->tablasPermitidas === false) {
            $usuario = $this->user();
            $this->tablasPermitidas = $usuario === null
                ? []
                : app(VisibilidadSecciones::class)->tablasVisiblesEn($usuario, $this->route('seccion'));
        }

        return $this->tablasPermitidas;
    }
}
