<?php

namespace Tests\Concerns;

use App\Models\NubeCuenta;
use App\Models\SeccionOrigen;
use App\Services\Nube\Contracts\ProveedorNube;
use App\Services\Nube\Datos\ArchivoRemoto;
use App\Services\Nube\Datos\ListadoRemoto;
use App\Services\Nube\Datos\OrigenRemoto;
use Throwable;

/**
 * Nube de mentira para probar el motor de sincronización sin red: se registra
 * en el contenedor en lugar de EnlacePublico / MicrosoftGraph.
 */
final class ProveedorNubeFalso implements ProveedorNube
{
    /** @var list<ArchivoRemoto> */
    public array $archivos = [];

    /** @var array<string, int> */
    public array $omitidos = [];

    /** @var array<string, string> remoto_id => bytes */
    public array $contenidos = [];

    public ?Throwable $falloAlListar = null;

    /** @var array<string, Throwable> */
    public array $falloAlDescargar = [];

    public int $listados = 0;

    public int $descargas = 0;

    public int $inspecciones = 0;

    public ?OrigenRemoto $inspeccion = null;

    /**
     * @param  array<string, mixed>  $extra
     */
    public function poner(string $id, string $ruta, string $bytes, string $version, array $extra = []): self
    {
        $this->archivos = array_values(array_filter($this->archivos, fn (ArchivoRemoto $a) => $a->id !== $id));
        $this->archivos[] = new ArchivoRemoto($id, basename($ruta), $ruta, $extra['tamano'] ?? strlen($bytes), $version);
        $this->contenidos[$id] = $bytes;

        return $this;
    }

    public function quitar(string $id): self
    {
        $this->archivos = array_values(array_filter($this->archivos, fn (ArchivoRemoto $a) => $a->id !== $id));

        return $this;
    }

    public function inspeccionar(string $enlace, ?NubeCuenta $cuenta): OrigenRemoto
    {
        $this->inspecciones++;

        return $this->inspeccion ?? new OrigenRemoto(OrigenRemoto::CARPETA, null, 'raiz-1', 'Carpeta de prueba', count($this->archivos), $this->omitidos, 1);
    }

    public function listar(SeccionOrigen $origen): array
    {
        return $this->listarConResumen($origen)->archivos;
    }

    public function listarConResumen(SeccionOrigen $origen): ListadoRemoto
    {
        $this->listados++;

        if ($this->falloAlListar !== null) {
            throw $this->falloAlListar;
        }

        return new ListadoRemoto($this->archivos, $this->omitidos);
    }

    public function descargar(SeccionOrigen $origen, ArchivoRemoto $archivo, string $destino): void
    {
        $this->descargas++;

        if (isset($this->falloAlDescargar[$archivo->id])) {
            throw $this->falloAlDescargar[$archivo->id];
        }

        file_put_contents($destino, $this->contenidos[$archivo->id]);
    }
}
