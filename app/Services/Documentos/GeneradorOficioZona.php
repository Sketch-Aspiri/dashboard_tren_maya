<?php

namespace App\Services\Documentos;

use App\Enums\EstatusAsistencia;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\TemplateProcessor;

/**
 * Fills resources/documentos/asistencia_zona.docx with the whole zone's day
 * of attendance. Pure file generation, like GeneradorOficioEstacion.
 */
final class GeneradorOficioZona
{
    public const PLANTILLA = 'documentos/asistencia_zona.docx';

    /** Marker suffix => status counted in that column, for the three personnel tables. */
    private const COLUMNAS_RESUMEN = [
        'descanso' => EstatusAsistencia::Descanso,
        'dia_no_laborable' => EstatusAsistencia::DiaNoLaborable,
        'licencia_medica' => EstatusAsistencia::LicenciaMedica,
        'comision' => EstatusAsistencia::Comision,
        'aviso_incidencia' => EstatusAsistencia::AvisoIncidencia,
        'reporte_ausentismo' => EstatusAsistencia::ReporteAusentismo,
        'vacaciones' => EstatusAsistencia::Vacaciones,
        'bajas' => EstatusAsistencia::Baja,
        'nuevos_ingresos' => EstatusAsistencia::NuevoIngreso,
        'presente' => EstatusAsistencia::Presente,
    ];

    /**
     * Writes the finished .docx to $destino (an absolute path).
     */
    public function generar(OficioZonaData $data, MetadatosOficio $meta, string $destino): void
    {
        // Names, notes and destinations are user input: escape XML.
        Settings::setOutputEscapingEnabled(true);

        $plantilla = new TemplateProcessor(resource_path(self::PLANTILLA));
        $direccion = mb_strtoupper((string) config('asistencia_documentos.zona.direccion'));

        $plantilla->setValues([
            'numero_oficio' => $meta->numeroOficio,
            'fecha_corta' => FormatoFechaOficio::corta($data->fecha),
            'texto_suplencia' => $meta->textoSuplencia,
            'firmante_cargo' => $meta->firmanteCargo,
            'firmante_nombre' => $meta->firmanteNombre,
            'iniciales' => $meta->iniciales,
        ]);

        $this->llenarResumenPersonal($plantilla, 'mil', $data->resumenMilitares);
        $this->llenarResumenPersonal($plantilla, 'per', $data->resumenPermanentes);
        $this->llenarResumenPersonal($plantilla, 'ev', $data->resumenEventuales);
        $this->llenarResumenComisionados($plantilla, $data->resumenComisionados);

        $this->llenarLista($plantilla, 'm', $this->deFilasPersonal($data->militares, $direccion));
        $this->llenarLista($plantilla, 'p', $this->deFilasPersonal($data->permanentes, $direccion));
        $this->llenarLista($plantilla, 'e', $this->deFilasPersonal($data->eventuales, $direccion));
        $this->llenarLista($plantilla, 'otra', $this->deFilasComisionado($data->comisionadosOtrasCoordinaciones));
        $this->llenarLista($plantilla, 'fuera', $this->deFilasComisionado($data->comisionadosAOtrasAreas));
        $this->llenarLista($plantilla, 'fueraev', $this->deFilasComisionado($data->eventualesAOtrasAreas));

        $plantilla->saveAs($destino);
    }

    private function llenarResumenPersonal(TemplateProcessor $plantilla, string $prefijo, ResumenAsistencia $resumen): void
    {
        foreach (self::COLUMNAS_RESUMEN as $sufijo => $estatus) {
            $plantilla->setValue($prefijo.'_'.$sufijo, (string) $resumen->de($estatus));
        }

        $plantilla->setValue($prefijo.'_total', (string) $resumen->total());
    }

    private function llenarResumenComisionados(TemplateProcessor $plantilla, ResumenComisionados $resumen): void
    {
        $plantilla->setValues([
            'com_descanso' => (string) $resumen->de(EstatusAsistencia::Descanso),
            'com_dia_no_laborable' => (string) $resumen->de(EstatusAsistencia::DiaNoLaborable),
            'com_licencia_medica' => (string) $resumen->de(EstatusAsistencia::LicenciaMedica),
            'com_comision_permanente' => (string) $resumen->comisionPermanente,
            'com_comision_eventual' => (string) $resumen->comisionEventual,
            'com_comision_otra' => (string) $resumen->comisionOtraCoordinacion,
            'com_aviso_incidencia' => (string) $resumen->de(EstatusAsistencia::AvisoIncidencia),
            'com_reporte_ausentismo' => (string) $resumen->de(EstatusAsistencia::ReporteAusentismo),
            'com_vacaciones' => (string) $resumen->de(EstatusAsistencia::Vacaciones),
            'com_bajas' => (string) $resumen->de(EstatusAsistencia::Baja),
            'com_nuevos_ingresos' => (string) $resumen->de(EstatusAsistencia::NuevoIngreso),
            'com_presente' => '0',
            'com_total' => (string) $resumen->total,
        ]);
    }

    /**
     * @param  list<FilaPersonal>  $filas
     * @return list<array{dir: string, trab: string, nombre: string, estatus: string, ubic: string}>
     */
    private function deFilasPersonal(array $filas, string $direccion): array
    {
        return array_map(fn (FilaPersonal $fila) => [
            'dir' => $direccion,
            'trab' => $fila->noEmpleado,
            'nombre' => mb_strtoupper($fila->nombre),
            'estatus' => $fila->estatusTextoZona(),
            'ubic' => $fila->ubicacion,
        ], $filas);
    }

    /**
     * @param  list<FilaComisionado>  $filas
     * @return list<array{dir: string, trab: string, nombre: string, estatus: string, ubic: string}>
     */
    private function deFilasComisionado(array $filas): array
    {
        return array_map(fn (FilaComisionado $fila) => [
            'dir' => $fila->direccion,
            'trab' => $fila->noEmpleado,
            'nombre' => $fila->nombre,
            'estatus' => $fila->estatus,
            'ubic' => $fila->ubicacion,
        ], $filas);
    }

    /**
     * Clones the template row (anchored on ${<prefijo>_no}) once per line,
     * numbering them 1..n. With no lines the row is kept blank so the table
     * keeps its shape, like the empty tables of the original oficio.
     *
     * @param  list<array{dir: string, trab: string, nombre: string, estatus: string, ubic: string}>  $filas
     */
    private function llenarLista(TemplateProcessor $plantilla, string $prefijo, array $filas): void
    {
        $valores = [];

        foreach ($filas as $indice => $fila) {
            $valores[] = [
                $prefijo.'_no' => (string) ($indice + 1),
                $prefijo.'_dir' => $fila['dir'],
                $prefijo.'_trab' => $fila['trab'],
                $prefijo.'_nombre' => $fila['nombre'],
                $prefijo.'_estatus' => $fila['estatus'],
                $prefijo.'_ubic' => $fila['ubic'],
            ];
        }

        if ($valores === []) {
            $valores[] = [
                $prefijo.'_no' => '', $prefijo.'_dir' => '', $prefijo.'_trab' => '',
                $prefijo.'_nombre' => '', $prefijo.'_estatus' => '', $prefijo.'_ubic' => '',
            ];
        }

        $plantilla->cloneRowAndSetValues($prefijo.'_no', $valores);
    }
}
