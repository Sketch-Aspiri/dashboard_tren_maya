<?php

/*
|--------------------------------------------------------------------------
| Oficios de asistencia (Control de Asistencia Diaria)
|--------------------------------------------------------------------------
|
| Texto fijo y ajustes de los documentos autogenerados (.docx/.pdf). El
| formato reproduce el que imprime el Excel automatizado (n8n): membrete y
| pie de página son imágenes en resources/documentos/assets (el pie lleva la
| leyenda del año: cámbiala ahí cada año). Nunca leer env() fuera de este
| archivo: tras `config:cache` devolvería null.
|
*/

return [

    /*
     | Disco privado y carpeta donde se guardan los archivos generados. Contienen
     | datos personales: jamás en un disco público.
     */
    'disk' => 'local',
    'directorio' => 'documentos-asistencia',

    /*
     | LibreOffice (headless) convierte el .docx generado a PDF para que ambos
     | archivos sean idénticos. En Windows suele ser
     | C:\Program Files\LibreOffice\program\soffice.exe; en el VPS, `soffice`.
     */
    'soffice_path' => env('ASISTENCIA_SOFFICE_PATH') ?: (function (): string {
        // Not on PATH by default on Windows: try the usual install locations,
        // then fall back to plain "soffice" (the VPS's apt package puts it on PATH).
        foreach ([
            'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
            'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
            '/usr/bin/soffice',
            '/usr/local/bin/soffice',
        ] as $ruta) {
            if (is_file($ruta)) {
                return $ruta;
            }
        }

        return 'soffice';
    })(),
    // Below Nginx's default fastcgi_read_timeout (60 s + queueing): the user gets an
    // error message rather than a 504 while the server keeps working.
    'conversion_timeout' => 55,

    /*
     | Firma digital que se importa al generar el oficio (PNG o JPG). Se
     | reescala y se guarda como PNG en el disco privado.
     */
    'firma' => [
        'max_kb' => 2048,
        'max_lado_px' => 900,
        // Rechaza imágenes enormes antes de decodificarlas (consumo de memoria).
        'max_dimension_origen_px' => 6000,
    ],

    /*
     | Texto fijo del formato del Excel automatizado (igual en estación y zona).
     */
    'formato' => [
        'titulo' => 'CONTROL DE ASISTENCIA DIARIA',
        'destinatario' => 'Para atención del C. Coord. Gral. de R.H., “Tren Maya S.A de C.V.”',
        'memorandum' => 'TMM/DRH/Admón./877/847 de fecha 14 marzo de 2025',
        'despedida' => 'Respetuosamente.',

        /*
         | Orden de las estaciones en las listas del oficio de zona (ruta de
         | norte a sur; el Edificio Zonal va al final), como en el Excel.
         */
        'orden_ubicaciones' => [
            'Puerto Morelos',
            'Playa del Carmen',
            'Tulum',
            'Tulum Aeropuerto',
            'Felipe Carrillo Puerto',
            'Limones',
            'Bacalar',
            'Chetumal',
            'Kohunlich',
            'Edificio Zonal Este',
        ],
    ],

    /*
     | Sugerencias iniciales de la pantalla de captura; se les suman los valores
     | que ya se han capturado.
     */
    'captura' => [
        'coordinaciones' => ['CGGIF', 'CGOFP'],
        'ubicaciones' => ['EDIFICIO DE LA DIRECCIÓN GENERAL MÉRIDA', 'EDIFICIO TEC. OPE. CAP. CANCÚN'],
    ],

    'estacion' => [
        /*
         | Clave de cada estación dentro del No. de oficio (T.M.M./RR.HH./23PMO/00340/2026).
         | Solo se conoce la de Puerto Morelos; para las demás el folio sugerido
         | omite la clave y quien genera el oficio la captura a mano.
         */
        'claves' => [
            'Puerto Morelos' => '23PMO',
        ],

        // Quien firma por defecto en las oficinas que no son estación (Edificio Zonal Este).
        'firmante_oficina_cargo' => 'Dirección de Gestión Territorial Zona Oriente',
        'firmante_oficina_nombre' => 'Lic. Miguel Ángel Milanez Navarrete',
    ],

    'zona' => [
        'direccion' => 'Dir. Gest. Territ. Oriente',
        'sujeto' => 'Dirección Zona Oriente',
        'folio_prefijo' => 'TM/UAI/CGGIF/DGTZO/',

        /*
         | Quién puede firmar el oficio de zona. "suplencia" agrega el párrafo de
         | suplencia (el Subgerente firma por ausencia temporal del Director).
         */
        'firmantes' => [
            'director' => [
                'etiqueta' => 'Director de Gestión Territorial Zona Oriente',
                'cargo' => 'Dirección de Gestión Territorial Zona Oriente',
                'nombre' => 'Lic. Miguel Angel Milanez Navarrete',
                'iniciales' => 'jatp-aaga',
                'suplencia' => false,
            ],
            'subgerente' => [
                'etiqueta' => 'Subgerente de Operación y Enlace (por suplencia)',
                'cargo' => 'Subgerencia de Operación y Enlace con estaciones Zona Oriente',
                'nombre' => 'Mtro. Jesús Alberto Tec Pimentel',
                'iniciales' => 'JATP-narv',
                'suplencia' => true,
            ],
        ],

        'texto_suplencia' => 'El presente documento lo firmo en mi calidad de Subgerencia de Operación y Enlace con estaciones Zona Oriente por ausencia temporal de la Dirección de Gestión Territorial Zona Oriente, de conformidad con lo establecido en el Capítulo VII de las Suplencias, numerales 2 y 4 del Manual de Organización General de Tren Maya, S.A de C.V., aprobado en la primera sesión extraordinaria 2024 del Consejo de Administración de Tren Maya, S.A. de C.V, publicado en el Diario Oficial de la Federación el 09 de abril de 2025.',
    ],

];
