<?php

namespace App\Services\Nube;

use App\Enums\MotivoOmision;
use App\Enums\MotivoTruncado;
use App\Services\Nube\Datos\ArchivoRemoto;
use App\Services\Nube\Datos\ListadoRemoto;
use Carbon\CarbonImmutable;

/**
 * Junta los archivos de un recorrido remoto aplicando los topes del plan:
 * máximo de archivos por origen, topes globales del listado (peticiones,
 * entradas revisadas y tiempo: al alcanzarlos queda `truncado`) y las reglas de qué se omite y por qué
 * (extensión, tamaño, zip, accesos directos de Google).
 */
final class AcumuladorListado
{
    /** @var list<ArchivoRemoto> */
    private array $archivos = [];

    /** @var array<string, int> */
    private array $omitidos = [];

    private readonly int $maximo;

    private readonly int $maxPeticiones;

    private readonly int $maxEntradas;

    private readonly CarbonImmutable $limiteDeTiempo;

    private int $peticiones = 0;

    private int $entradas = 0;

    private ?MotivoTruncado $truncado = null;

    /** @var array<string, true> */
    private array $visitados = [];

    /**
     * @param  bool  $inspeccion  corre dentro de la petición web: topes más bajos
     */
    public function __construct(bool $inspeccion = false)
    {
        $this->maximo = (int) config('secciones.nube.max_archivos_por_origen');
        $this->maxEntradas = 10 * $this->maximo;
        $this->maxPeticiones = (int) config($inspeccion ? 'secciones.nube.max_peticiones_inspeccion' : 'secciones.nube.max_peticiones_listado');
        $this->limiteDeTiempo = now()->toImmutable()->addSeconds((int) config($inspeccion ? 'secciones.nube.max_segundos_inspeccion' : 'secciones.nube.max_segundos_listado'));
    }

    /**
     * Pide permiso para una petición más a la nube. Si se alcanzó un tope
     * (peticiones o tiempo) el listado queda truncado y devuelve false.
     */
    public function permitirPeticion(): bool
    {
        if ($this->detenido()) {
            return false;
        }

        if ($this->peticiones >= $this->maxPeticiones) {
            $this->truncado = MotivoTruncado::Peticiones;

            return false;
        }

        $this->peticiones++;

        return true;
    }

    /**
     * Cuenta una entrada revisada (archivo, carpeta u omitida). false = parar.
     */
    public function permitirEntrada(): bool
    {
        if ($this->detenido()) {
            return false;
        }

        if ($this->entradas >= $this->maxEntradas) {
            $this->truncado = MotivoTruncado::Entradas;

            return false;
        }

        $this->entradas++;

        return true;
    }

    /**
     * Marca una carpeta como visitada; true si ya lo estaba (ciclo por accesos directos).
     */
    public function yaVisitado(string $clave): bool
    {
        if (isset($this->visitados[$clave])) {
            return true;
        }

        $this->visitados[$clave] = true;

        return false;
    }

    public function truncado(): ?MotivoTruncado
    {
        return $this->truncado;
    }

    private function detenido(): bool
    {
        if ($this->truncado === null && now()->greaterThanOrEqualTo($this->limiteDeTiempo)) {
            $this->truncado = MotivoTruncado::Tiempo;
        }

        return $this->truncado !== null;
    }

    public function lleno(): bool
    {
        return count($this->archivos) >= $this->maximo;
    }

    /**
     * Agrega el archivo si pasa las reglas; si no, lo cuenta como omitido.
     */
    public function agregar(ArchivoRemoto $archivo, bool $esAccesoDirecto = false): void
    {
        $motivo = $this->motivoDeOmision($archivo, $esAccesoDirecto);

        if ($motivo === null && $this->lleno()) {
            $motivo = MotivoOmision::TopeArchivos;
        }

        if ($motivo !== null) {
            $this->omitir($motivo);

            return;
        }

        $this->archivos[] = $archivo;
    }

    public function omitir(MotivoOmision $motivo): void
    {
        // Lo que quedó fuera del tope no se vio: el listado está incompleto y no debe archivar nada.
        if ($motivo === MotivoOmision::TopeArchivos) {
            $this->truncado ??= MotivoTruncado::Archivos;
        }

        $this->omitidos[$motivo->value] = ($this->omitidos[$motivo->value] ?? 0) + 1;
    }

    public function resultado(): ListadoRemoto
    {
        return new ListadoRemoto($this->archivos, $this->omitidos, $this->truncado);
    }

    private function motivoDeOmision(ArchivoRemoto $archivo, bool $esAccesoDirecto): ?MotivoOmision
    {
        $extension = strtolower(pathinfo($archivo->nombre, PATHINFO_EXTENSION));

        return match (true) {
            $esAccesoDirecto => MotivoOmision::AccesoDirectoGoogle,
            $extension === 'zip' => MotivoOmision::Zip,
            ! in_array($extension, config('secciones.extensiones'), true) => MotivoOmision::ExtensionNoPermitida,
            $archivo->tamano !== null && $archivo->tamano > (int) config('secciones.max_archivo_kb') * 1024 => MotivoOmision::Grande,
            default => null,
        };
    }
}
