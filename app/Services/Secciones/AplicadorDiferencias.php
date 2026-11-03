<?php

namespace App\Services\Secciones;

use App\Enums\MotivoOmision;
use App\Models\SeccionArchivo;
use App\Models\SeccionOrigen;
use App\Models\User;
use App\Services\Nube\ClasificadorErrorNube;
use App\Services\Nube\Contracts\ProveedorNube;
use App\Services\Nube\Datos\ArchivoRemoto;
use App\Services\Nube\Datos\Diferencias;
use App\Services\Nube\Datos\ResultadoCiclo;
use App\Services\Nube\NubeTransitoriaException;
use App\Services\Nube\ProveedorNubeFactory;
use Closure;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Lleva a los documentos de un origen lo que calculó CalculadorDiferencias:
 * altas, cambios de contenido, renombres/movimientos, restauraciones y bajas
 * (archivar = soft delete, restaurable). Cada archivo falla por separado; solo
 * la cuenta que pide reconexión, un fallo pasajero o el disco lleno detienen
 * todo el ciclo.
 */
final class AplicadorDiferencias
{
    public function __construct(
        private readonly ProveedorNubeFactory $proveedores,
        private readonly ArchivoService $archivos,
        private readonly AplicadorContenidoNube $contenido,
        private readonly ClasificadorErrorNube $clasificador,
    ) {}

    /**
     * @throws NubeSyncException con la cuenta en reconexión
     * @throws NubeTransitoriaException
     * @throws DiscoInsuficienteException
     */
    public function aplicar(SeccionOrigen $origen, Diferencias $diferencias, ?User $usuario): ResultadoCiclo
    {
        $resultado = new ResultadoCiclo;
        $proveedor = $this->proveedores->para($origen);

        foreach ($diferencias->altas as $remoto) {
            $this->protegido($origen, $resultado, fn () => $this->alta($proveedor, $origen, $remoto, $usuario, $resultado));
        }

        foreach ($diferencias->actualizar as $cambio) {
            $this->protegido($origen, $resultado, fn () => $this->actualizar($proveedor, $origen, $cambio, $usuario, $resultado));
        }

        foreach ($diferencias->bajas as $archivo) {
            $this->archivar($origen, $archivo, $usuario);
            $resultado->archivados++;
        }

        return $resultado;
    }

    /**
     * Un archivo con problema se cuenta como omitido y el ciclo sigue.
     */
    private function protegido(SeccionOrigen $origen, ResultadoCiclo $resultado, Closure $accion): void
    {
        try {
            $accion();
        } catch (NubeSyncException $e) {
            $tipo = $this->clasificador->clasificar($e, $origen->cuenta);

            if ($tipo === ClasificadorErrorNube::RECONEXION) {
                throw $e;
            }

            if ($tipo === ClasificadorErrorNube::TRANSITORIO) {
                throw new NubeTransitoriaException($e->getMessage(), 0, $e);
            }

            $resultado->omitir(MotivoOmision::ErrorDescarga, $e->getMessage());
        }
    }

    private function alta(ProveedorNube $proveedor, SeccionOrigen $origen, ArchivoRemoto $remoto, ?User $usuario, ResultadoCiclo $resultado): void
    {
        $temporal = $this->descargar($proveedor, $origen, $remoto);

        try {
            $nombre = $this->nombreFinal($origen, $remoto, $temporal);

            if ($nombre === null) {
                $resultado->omitir(MotivoOmision::ExtensionNoPermitida);

                return;
            }

            if (! $this->esContenidoValido($nombre, $temporal)) {
                $resultado->omitir(MotivoOmision::ContenidoInvalido);

                return;
            }

            $archivo = $this->archivos->guardarDesdeRuta($origen->seccion, $temporal, $nombre, $usuario, $origen, $remoto);
            $this->registrar('documento_agregado_nube', $archivo, $origen, $usuario, 'Documento agregado desde la nube.');
            $resultado->altas++;
        } finally {
            @unlink($temporal);
        }
    }

    /**
     * @param  array{archivo: SeccionArchivo, remoto: ArchivoRemoto, contenido: bool, renombre: bool, restaurar: bool}  $cambio
     */
    private function actualizar(ProveedorNube $proveedor, SeccionOrigen $origen, array $cambio, ?User $usuario, ResultadoCiclo $resultado): void
    {
        ['archivo' => $archivo, 'remoto' => $remoto] = $cambio;
        $extension = Str::lower(pathinfo($remoto->nombre, PATHINFO_EXTENSION));
        $descargar = $cambio['contenido'] || ($cambio['renombre'] && $extension !== $archivo->extension && $this->extensionPermitida($extension));
        $temporal = null;
        $hash = null;

        try {
            if ($descargar) {
                $temporal = $this->descargar($proveedor, $origen, $remoto);
                $hash = (string) hash_file('sha256', $temporal);
                $descargar = $hash !== $archivo->contenido_hash;

                if ($descargar && ! $this->esContenidoValido($archivo->nombre_original, $temporal)) {
                    $resultado->omitir(MotivoOmision::ContenidoInvalido);

                    return;
                }
            }

            $this->aplicarCambio($origen, $archivo, $remoto, $cambio, $descargar ? [(string) $temporal, (string) $hash] : null, $usuario, $resultado);
        } finally {
            if ($temporal !== null) {
                @unlink($temporal);
            }
        }
    }

    /**
     * @param  array{renombre: bool, restaurar: bool}  $cambio
     * @param  array{0: string, 1: string}|null  $nuevoContenido  [ruta temporal, sha256] si el contenido cambió de verdad
     */
    private function aplicarCambio(SeccionOrigen $origen, SeccionArchivo $archivo, ArchivoRemoto $remoto, array $cambio, ?array $nuevoContenido, ?User $usuario, ResultadoCiclo $resultado): void
    {
        $archivo->disableLogging();

        if ($cambio['restaurar']) {
            $archivo->restore();
            $this->registrar('documento_restaurado_nube', $archivo, $origen, $usuario, 'Documento restaurado: volvió a aparecer en la nube.');
            $resultado->restaurados++;
        }

        $archivo->forceFill($this->archivos->atributosRemotos($remoto));

        if ($cambio['renombre']) {
            $nombre = Str::limit($remoto->nombre, 250, '');
            $extension = Str::lower(pathinfo($nombre, PATHINFO_EXTENSION));
            $archivo->forceFill(['nombre_original' => $nombre] + ($nuevoContenido !== null && $this->extensionPermitida($extension) ? ['extension' => $extension] : []));
        }

        if ($nuevoContenido !== null) {
            $resultado->aviso = $this->contenido->reemplazar($archivo, $origen, $nuevoContenido[0], $nuevoContenido[1], $usuario) ?? $resultado->aviso;
            $resultado->actualizados++;

            return;
        }

        $archivo->save();

        if ($cambio['renombre']) {
            $this->registrar('documento_sincronizado', $archivo, $origen, $usuario, 'Documento renombrado o movido en la nube.', ['renombrado' => true]);
            $resultado->actualizados++;
        }
    }

    private function archivar(SeccionOrigen $origen, SeccionArchivo $archivo, ?User $usuario): void
    {
        $archivo->disableLogging();
        $archivo->delete();
        $this->registrar('documento_archivado_nube', $archivo, $origen, $usuario, 'Documento archivado: ya no está en la nube.');
    }

    /**
     * Sin tokens ni enlaces: solo nombre, ruta dentro del origen y proveedor.
     *
     * @param  array<string, mixed>  $extra
     */
    private function registrar(string $evento, SeccionArchivo $archivo, SeccionOrigen $origen, ?User $usuario, string $mensaje, array $extra = []): void
    {
        activity('seccion')
            ->causedBy($usuario)
            ->performedOn($archivo)
            ->event($evento)
            ->withProperties(['proveedor' => $origen->proveedor, 'origen' => $origen->nombre, 'nombre' => $archivo->nombre_original, 'ruta' => $archivo->remoto_ruta] + $extra)
            ->log($mensaje);
    }

    /**
     * @throws DiscoInsuficienteException
     * @throws NubeSyncException
     */
    private function descargar(ProveedorNube $proveedor, SeccionOrigen $origen, ArchivoRemoto $remoto): string
    {
        $this->verificarDisco($remoto->tamano);
        $temporal = (string) tempnam(sys_get_temp_dir(), 'nube');

        try {
            $proveedor->descargar($origen, $remoto, $temporal);
        } catch (Throwable $e) {
            @unlink($temporal);

            throw $e;
        }

        return $temporal;
    }

    private function verificarDisco(?int $esperado): void
    {
        $reserva = (float) config('secciones.nube.reserva_disco_gb') * 1024 ** 3;
        $libre = @disk_free_space(Storage::disk($this->archivos->disco())->path(''));

        if ($libre === false || $libre - (float) $esperado >= $reserva) {
            return;
        }

        throw new DiscoInsuficienteException(__('Espacio en disco insuficiente: quedan :libre GB libres y se necesita dejar al menos :reserva GB de reserva. Libera espacio y vuelve a sincronizar.', [
            'libre' => number_format($libre / 1024 ** 3, 1),
            'reserva' => number_format($reserva / 1024 ** 3, 1),
        ]));
    }

    private function extensionPermitida(string $extension): bool
    {
        return in_array($extension, config('secciones.extensiones'), true);
    }

    /**
     * Nombre con extensión permitida. Un enlace público no dice la extensión:
     * se deduce del contenido descargado (y se recuerda en el origen).
     */
    private function nombreFinal(SeccionOrigen $origen, ArchivoRemoto $remoto, string $temporal): ?string
    {
        if ($this->extensionPermitida(Str::lower(pathinfo($remoto->nombre, PATHINFO_EXTENSION)))) {
            return $remoto->nombre;
        }

        if ($origen->cuenta === null) {
            $adivinada = (new File($temporal))->guessExtension();

            if ($adivinada !== null && $this->extensionPermitida($adivinada)) {
                $nombre = $remoto->nombre.'.'.$adivinada;
                $origen->forceFill(['nombre' => Str::limit($nombre, 250, '')])->save();

                return $nombre;
            }
        }

        return null;
    }

    private function esContenidoValido(string $nombre, string $temporal): bool
    {
        try {
            $this->contenido->validarContenido($nombre, $temporal);

            return true;
        } catch (NubeSyncException) {
            return false;
        }
    }
}
