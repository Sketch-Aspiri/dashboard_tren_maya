<?php

/*
 * Secciones dinámicas (tablas configurables + documentos). Ver
 * app/Services/Secciones.
 */
return [

    // Tamaño máximo por archivo subido, en KB (102400 = 100 MB). En el VPS
    // deben coincidir upload_max_filesize/post_max_size de PHP-FPM y
    // client_max_body_size de Nginx (docs/despliegue-produccion.md).
    'max_archivo_kb' => (int) env('SECCIONES_MAX_ARCHIVO_KB', 102400),

    // Extensiones permitidas. Se valida la extensión Y el tipo real del
    // contenido (StoreSeccionArchivoRequest).
    'extensiones' => ['xlsx', 'xls', 'csv', 'docx', 'doc', 'pdf', 'jpg', 'jpeg', 'png', 'pptx'],

    // Por encima de este tamaño (KB) el archivo solo se guarda: no se leen
    // tablas ni texto (evita agotar memoria/tiempo de PHP con un Excel o
    // PDF enorme).
    'max_lectura_kb' => (int) env('SECCIONES_MAX_LECTURA_KB', 25600),

    // Límites de una importación.
    'max_filas_importacion' => 20000,
    'max_columnas' => 100,

    // Hojas que se cargan de una vez al subir un libro de Excel.
    'max_hojas_importacion' => 30,

    // Texto máximo que se indexa por documento (caracteres).
    'max_texto_indexado' => 1000000,

    // Límites de la vista previa de documentos (PrevisualizadorArchivos).
    'vista_previa_filas' => 500,
    'vista_previa_columnas' => 30,
    'vista_previa_caracteres' => 100000,

    // Defensa contra "bombas zip" al previsualizar xlsx/docx/pptx: tope por
    // entrada y total descomprimido (MB) y razón de compresión máxima.
    'vista_previa_zip_entrada_mb' => 50,
    'vista_previa_zip_total_mb' => 100,
    'vista_previa_zip_ratio' => 100,

    'filas_por_pagina' => [25, 50, 100],

    // Disco privado donde viven los archivos (nunca el disco "public").
    'disco' => 'local',
];
