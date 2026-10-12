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

    // Texto máximo que se indexa por documento (caracteres).
    'max_texto_indexado' => 1000000,

    'filas_por_pagina' => [25, 50, 100],

    // Disco privado donde viven los archivos (nunca el disco "public").
    'disco' => 'local',
];
