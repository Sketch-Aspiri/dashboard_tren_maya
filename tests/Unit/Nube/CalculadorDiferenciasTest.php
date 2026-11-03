<?php

namespace Tests\Unit\Nube;

use App\Models\SeccionArchivo;
use App\Services\Nube\CalculadorDiferencias;
use App\Services\Nube\Datos\ArchivoRemoto;
use Tests\TestCase;

/**
 * Servicio puro: sin base de datos ni red.
 */
class CalculadorDiferenciasTest extends TestCase
{
    private function local(string $remotoId, string $ruta, ?string $version, bool $archivado = false): SeccionArchivo
    {
        $archivo = new SeccionArchivo;
        $archivo->forceFill([
            'remoto_id' => $remotoId,
            'remoto_ruta' => $ruta,
            'remoto_version' => $version,
            'nombre_original' => basename($ruta),
            'deleted_at' => $archivado ? '2026-11-01 10:00:00' : null,
        ]);

        return $archivo;
    }

    private function remoto(string $id, string $ruta, ?string $version): ArchivoRemoto
    {
        return new ArchivoRemoto($id, basename($ruta), $ruta, 10, $version);
    }

    public function test_remote_file_without_local_copy_is_an_addition(): void
    {
        $diferencias = (new CalculadorDiferencias)->calcular([$this->remoto('a', 'x/a.xlsx', 'v1')], []);

        $this->assertCount(1, $diferencias->altas);
        $this->assertSame('a', $diferencias->altas[0]->id);
        $this->assertSame([], $diferencias->actualizar);
        $this->assertSame([], $diferencias->bajas);
    }

    public function test_unchanged_file_is_not_touched(): void
    {
        $diferencias = (new CalculadorDiferencias)->calcular(
            [$this->remoto('a', 'x/a.xlsx', 'v1')],
            [$this->local('a', 'x/a.xlsx', 'v1')],
        );

        $this->assertSame(1, $diferencias->sinCambios);
        $this->assertSame([], $diferencias->altas);
        $this->assertSame([], $diferencias->actualizar);
    }

    public function test_different_version_is_a_content_change(): void
    {
        $diferencias = (new CalculadorDiferencias)->calcular(
            [$this->remoto('a', 'x/a.xlsx', 'v2')],
            [$this->local('a', 'x/a.xlsx', 'v1')],
        );

        $this->assertCount(1, $diferencias->actualizar);
        $this->assertTrue($diferencias->actualizar[0]['contenido']);
        $this->assertFalse($diferencias->actualizar[0]['renombre']);
    }

    public function test_unknown_remote_version_asks_to_verify_the_content(): void
    {
        $diferencias = (new CalculadorDiferencias)->calcular(
            [$this->remoto('a', 'a.xlsx', null)],
            [$this->local('a', 'a.xlsx', null)],
        );

        $this->assertTrue($diferencias->actualizar[0]['contenido']);
    }

    public function test_rename_or_move_alone_does_not_ask_for_a_download(): void
    {
        $diferencias = (new CalculadorDiferencias)->calcular(
            [$this->remoto('a', 'nueva/b.xlsx', 'v1')],
            [$this->local('a', 'x/a.xlsx', 'v1')],
        );

        $this->assertCount(1, $diferencias->actualizar);
        $this->assertFalse($diferencias->actualizar[0]['contenido']);
        $this->assertTrue($diferencias->actualizar[0]['renombre']);
    }

    public function test_live_local_file_missing_from_the_cloud_is_a_removal(): void
    {
        $diferencias = (new CalculadorDiferencias)->calcular(
            [$this->remoto('a', 'a.xlsx', 'v1')],
            [$this->local('a', 'a.xlsx', 'v1'), $this->local('b', 'b.xlsx', 'v1')],
        );

        $this->assertCount(1, $diferencias->bajas);
        $this->assertSame('b', $diferencias->bajas[0]->remoto_id);
        $this->assertFalse($diferencias->cambioMasivoRetenido);
    }

    public function test_archived_file_that_is_not_in_the_cloud_is_left_alone(): void
    {
        $diferencias = (new CalculadorDiferencias)->calcular(
            [$this->remoto('a', 'a.xlsx', 'v1')],
            [$this->local('a', 'a.xlsx', 'v1'), $this->local('b', 'b.xlsx', 'v1', archivado: true)],
        );

        $this->assertSame([], $diferencias->bajas);
    }

    public function test_archived_file_that_reappears_is_restored(): void
    {
        $diferencias = (new CalculadorDiferencias)->calcular(
            [$this->remoto('b', 'b.xlsx', 'v1')],
            [$this->local('b', 'b.xlsx', 'v1', archivado: true)],
        );

        $this->assertCount(1, $diferencias->actualizar);
        $this->assertTrue($diferencias->actualizar[0]['restaurar']);
        $this->assertFalse($diferencias->actualizar[0]['contenido']);
        $this->assertSame([], $diferencias->altas);
    }

    public function test_empty_listing_with_existing_documents_is_held_back_as_a_mass_change(): void
    {
        $diferencias = (new CalculadorDiferencias)->calcular([], [$this->local('a', 'a.xlsx', 'v1'), $this->local('b', 'b.xlsx', 'v1')]);

        $this->assertTrue($diferencias->cambioMasivoRetenido);
        $this->assertSame([], $diferencias->bajas);
    }

    public function test_confirmed_mass_change_archives_everything(): void
    {
        $diferencias = (new CalculadorDiferencias)->calcular([], [$this->local('a', 'a.xlsx', 'v1')], bajaMasivaConfirmada: true);

        $this->assertFalse($diferencias->cambioMasivoRetenido);
        $this->assertCount(1, $diferencias->bajas);
    }

    public function test_empty_listing_without_documents_is_just_empty(): void
    {
        $diferencias = (new CalculadorDiferencias)->calcular([], []);

        $this->assertFalse($diferencias->cambioMasivoRetenido);
        $this->assertSame([], $diferencias->bajas);
    }
}
