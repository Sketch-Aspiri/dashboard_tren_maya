<?php

namespace App\Services\Documentos;

use App\Enums\EstatusAsistencia;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\TemplateProcessor;

/**
 * Fills resources/documentos/asistencia_estacion.docx with one station's
 * day of attendance. Pure file generation: authorization, folio and
 * persistence belong to AsistenciaDocumentoService.
 */
final class GeneradorOficioEstacion
{
    public const PLANTILLA = 'documentos/asistencia_estacion.docx';

    /**
     * Writes the finished .docx to $destino (an absolute path).
     */
    public function generar(OficioEstacionData $data, MetadatosOficio $meta, string $destino): void
    {
        // Names and notes are user input: escape XML so "&", "<" or ">"
        // can never corrupt the .docx.
        Settings::setOutputEscapingEnabled(true);

        $plantilla = new TemplateProcessor(resource_path(self::PLANTILLA));
        $config = config('asistencia_documentos');

        $plantilla->setValues([
            'leyenda_anio' => $config['leyenda_anio'],
            'numero_oficio' => $meta->numeroOficio,
            'destinatario_nombre' => $config['estacion']['destinatario_nombre'],
            'destinatario_cargo' => $config['estacion']['destinatario_cargo'],
            'fecha_oficio' => FormatoFechaOficio::larga($data->fecha),
            'memorandum_referencia' => $config['estacion']['memorandum'],
            'fecha_control' => FormatoFechaOficio::largaConDe($data->fecha),
            'coordinacion' => $config['estacion']['coordinacion'],
            'firmante_cargo' => mb_strtoupper($meta->firmanteCargo),
            'firmante_nombre' => $meta->firmanteNombre,
            'copia_para' => $config['estacion']['copia_para'],
        ]);

        $this->llenarResumen($plantilla, $data->resumen);
        $this->llenarLista($plantilla, 'c', $data->filas);
        $this->llenarLista($plantilla, 'e', $data->bajas());

        $plantilla->saveAs($destino);
    }

    private function llenarResumen(TemplateProcessor $plantilla, ResumenAsistencia $resumen): void
    {
        $columnas = [
            'a_descanso' => EstatusAsistencia::Descanso,
            'a_dia_no_laborable' => EstatusAsistencia::DiaNoLaborable,
            'a_licencia_medica' => EstatusAsistencia::LicenciaMedica,
            'a_comision' => EstatusAsistencia::Comision,
            'a_aviso_incidencia' => EstatusAsistencia::AvisoIncidencia,
            'a_reporte_ausentismo' => EstatusAsistencia::ReporteAusentismo,
            'a_vacaciones' => EstatusAsistencia::Vacaciones,
            'a_bajas' => EstatusAsistencia::Baja,
            'a_nuevos_ingresos' => EstatusAsistencia::NuevoIngreso,
            'a_presentes' => EstatusAsistencia::Presente,
        ];

        foreach ($columnas as $marcador => $estatus) {
            $plantilla->setValue($marcador, (string) $resumen->de($estatus));
        }

        $plantilla->setValue('a_totales', (string) $resumen->total());
    }

    /**
     * Clones the template row (marked with ${<prefijo>_no}) once per person.
     * With no people the row is kept blank so the table keeps its shape,
     * exactly like the empty tables of the original oficio.
     *
     * @param  list<FilaPersonal>  $filas
     */
    private function llenarLista(TemplateProcessor $plantilla, string $prefijo, array $filas): void
    {
        $valores = [];
        foreach ($filas as $indice => $fila) {
            $valores[] = [
                $prefijo.'_no' => (string) ($indice + 1),
                $prefijo.'_trab' => $fila->noEmpleado,
                $prefijo.'_nombre' => mb_strtoupper($fila->nombre),
                $prefijo.'_estatus' => $fila->estatusTexto(enEstacion: true),
            ];
        }

        if ($valores === []) {
            $valores[] = [
                $prefijo.'_no' => '',
                $prefijo.'_trab' => '',
                $prefijo.'_nombre' => '',
                $prefijo.'_estatus' => '',
            ];
        }

        $plantilla->cloneRowAndSetValues($prefijo.'_no', $valores);
    }
}
