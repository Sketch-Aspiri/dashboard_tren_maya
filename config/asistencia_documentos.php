<?php

/*
|--------------------------------------------------------------------------
| Oficios de asistencia (Control de Asistencia Diaria)
|--------------------------------------------------------------------------
|
| Texto fijo y ajustes de los documentos autogenerados (.docx/.pdf). Los
| valores que cambian de un año a otro (leyenda, memorándums de referencia)
| viven aquí, no en las plantillas ni en el código. Nunca leer env() fuera
| de este archivo: tras `config:cache` devolvería null.
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

    'leyenda_anio' => '2026, Año de Margarita Maza Parada',

    'estacion' => [
        'destinatario_nombre' => 'Lic. Miguel Ángel Milanez Navarrete',
        'destinatario_cargo' => 'Dir. De Gest. Territorial Zona Oriente',
        'memorandum' => 'TM/RH/089, del 21 Mar. 2024',
        'coordinacion' => 'COORD. GRAL. DE GEST. DE INFRA. FERROVIARIA',
        'copia_para' => 'Coord. Gral. de R.H. de Tren Maya S.A. de C.V.',

        /*
         | Clave de cada estación dentro del No. de oficio (T.M.M./RR.HH./23PMO/00340/2026).
         | Solo se conoce la de Puerto Morelos; para las demás el folio sugerido
         | omite la clave y quien genera el oficio la captura a mano.
         */
        'claves' => [
            'Puerto Morelos' => '23PMO',
        ],
    ],

    'zona' => [
        'direccion' => 'Dir. Gest. Territ. Oriente',
        'folio_prefijo' => 'TM/UAI/CGGIF/DGTZO/',

        /*
         | Quién puede firmar el oficio de zona. "suplencia" agrega el párrafo de
         | suplencia (el Subgerente firma por ausencia temporal del Director).
         | Las iniciales del Director no constan en el ejemplo: completarlas aquí.
         */
        'firmantes' => [
            'director' => [
                'etiqueta' => 'Director de Gestión Territorial Zona Oriente',
                'cargo' => 'Dirección de Gestión Territorial Zona Oriente',
                'nombre' => 'Lic. Miguel Ángel Milanez Navarrete',
                'iniciales' => '',
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
