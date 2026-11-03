<?php

namespace Tests\Concerns;

use App\Enums\EstadoCuentaNube;
use App\Enums\ProveedorNube;
use App\Models\NubeCredencial;
use App\Models\NubeCuenta;
use App\Models\Seccion;
use App\Models\SeccionOrigen;

/**
 * Cuentas, credenciales y orígenes de nube de prueba. Los tokens son
 * cadenas obviamente falsas: nunca credenciales reales.
 */
trait InteractsWithNube
{
    protected function credencialesDeBd(ProveedorNube $proveedor, string $clientId = 'cid-prueba-1234', string $secreto = 'secreto-prueba-xyz'): NubeCredencial
    {
        return NubeCredencial::create([
            'proveedor' => $proveedor->value,
            'client_id' => $clientId,
            'client_secret' => $secreto,
        ]);
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    protected function cuentaDeNube(ProveedorNube $proveedor = ProveedorNube::Microsoft, array $atributos = []): NubeCuenta
    {
        return NubeCuenta::create(array_merge([
            'proveedor' => $proveedor->value,
            'cuenta_email' => 'persona@ejemplo.test',
            'cuenta_nombre' => 'Persona Prueba',
            'access_token' => 'token-acceso-falso',
            'refresh_token' => 'token-refresco-falso',
            'expira_en' => now()->addHour(),
            'scopes' => $proveedor->scopes(),
            'estado' => EstadoCuentaNube::Activa,
        ], $atributos));
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    protected function origenDeNube(NubeCuenta $cuenta, string $tipo, array $atributos = []): SeccionOrigen
    {
        return SeccionOrigen::forceCreate(array_merge([
            'seccion_id' => Seccion::factory()->create()->id,
            'nube_cuenta_id' => $cuenta->id,
            'tipo' => $tipo,
            'proveedor' => $cuenta->proveedor->value,
            'enlace_original' => 'https://ejemplo.test/enlace',
            'nombre' => 'Origen de prueba',
            'incluir_subcarpetas' => true,
            'estado' => 'activo',
        ], $atributos));
    }
}
