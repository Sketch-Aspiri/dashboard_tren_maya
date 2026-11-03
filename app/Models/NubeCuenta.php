<?php

namespace App\Models;

use App\Enums\EstadoCuentaNube;
use App\Enums\ProveedorNube;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Cuenta de nube conectada por OAuth (solo lectura). Los tokens se cifran en
 * reposo y no se serializan. La bitácora es explícita (CuentaNubeService).
 */
class NubeCuenta extends Model
{
    use SoftDeletes;

    protected $table = 'nube_cuentas';

    /** @var list<string> */
    protected $fillable = [
        'proveedor', 'cuenta_email', 'cuenta_nombre', 'tenant_id',
        'access_token', 'refresh_token', 'expira_en', 'scopes',
        'estado', 'ultimo_error', 'conectada_por',
    ];

    /** @var list<string> */
    protected $hidden = ['access_token', 'refresh_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'proveedor' => ProveedorNube::class,
            'estado' => EstadoCuentaNube::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expira_en' => 'datetime',
        ];
    }

    /**
     * @param  Builder<NubeCuenta>  $consulta
     * @return Builder<NubeCuenta>
     */
    public function scopeActivas(Builder $consulta): Builder
    {
        return $consulta->where('estado', EstadoCuentaNube::Activa->value);
    }

    public function estaActiva(): bool
    {
        return $this->estado === EstadoCuentaNube::Activa;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function conectadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'conectada_por');
    }

    /**
     * @return HasMany<SeccionOrigen, $this>
     */
    public function origenes(): HasMany
    {
        return $this->hasMany(SeccionOrigen::class, 'nube_cuenta_id');
    }
}
