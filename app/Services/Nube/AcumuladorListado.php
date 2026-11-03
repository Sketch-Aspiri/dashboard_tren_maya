<?php

namespace App\Services\Nube;

use App\Enums\MotivoOmision;
use App\Services\Nube\Datos\ArchivoRemoto;
use App\Services\Nube\Datos\ListadoRemoto;

/**
 * Junta los archivos de un recorrido remoto aplicando los topes del plan:
 * máximo de archivos por origen, y las reglas de qué se omite y por qué
 * (extensión, tamaño, zip, accesos directos de Google).
 */
final class AcumuladorListado
{
    /** @var list<ArchivoRemoto> */
    private array $archivos = [];

    /** @var array<string, int> */
    private array $omitidos = [];

    private readonly int $maximo;

    public function __construct()
    {
        $this->maximo = (int) config('secciones.nube.max_archivos_por_origen');
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
        $this->omitidos[$motivo->value] = ($this->omitidos[$motivo->value] ?? 0) + 1;
    }

    public function resultado(): ListadoRemoto
    {
        return new ListadoRemoto($this->archivos, $this->omitidos);
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
