<?php

namespace App\Models;

use App\Enums\ProveedorNube;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Client ID / Secret de la app OAuth de un proveedor. El secreto va cifrado
 * y oculto; nunca se muestra ni se registra en la bitácora (la bitácora de
 * cambios es explícita en CredencialesNubeService, sin LogsActivity).
 */
class NubeCredencial extends Model
{
    protected $table = 'nube_credenciales';

    /** @var list<string> */
    protected $fillable = ['proveedor', 'client_id', 'client_secret', 'tenant', 'actualizado_por'];

    /** @var list<string> */
    protected $hidden = ['client_secret'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'proveedor' => ProveedorNube::class,
            'client_secret' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actualizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizado_por');
    }
}
