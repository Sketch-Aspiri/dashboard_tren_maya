<?php

namespace Database\Factories;

use App\Enums\TipoDocumentoAsistencia;
use App\Models\DocumentoAsistencia;
use App\Models\Estacion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Dummy data only (per .claude/rules/testing.md "Dummy Data" section).
 *
 * @extends Factory<DocumentoAsistencia>
 */
class DocumentoAsistenciaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tipo' => TipoDocumentoAsistencia::Estacion->value,
            'estacion_id' => Estacion::factory(),
            'fecha' => now()->toDateString(),
            'numero_oficio' => 'T.M.M./RR.HH./'.fake()->unique()->numerify('#####').'/2026',
            'firmante_cargo' => 'Gerente de Estación Dummy',
            'firmante_nombre' => 'Firmante Dummy',
            'es_suplencia' => false,
            'docx_path' => 'documentos-asistencia/test/'.fake()->uuid().'.docx',
            'pdf_path' => 'documentos-asistencia/test/'.fake()->uuid().'.pdf',
            'generado_por' => null,
        ];
    }

    public function zona(): static
    {
        return $this->state(fn () => [
            'tipo' => TipoDocumentoAsistencia::Zona->value,
            'estacion_id' => null,
            'numero_oficio' => 'TM/UAI/CGGIF/DGTZO/'.fake()->unique()->numerify('####'),
        ]);
    }
}
