<?php

return [
    /*
    | Con false, las cuentas Estación conservan el acceso por estación de siempre (útil mientras se les asigna
    | área). Con true, una cuenta Estación solo usa los módulos de su área; sin área no usa ninguno.
    */
    'estrictas' => (bool) env('AREAS_ESTRICTAS', false),
];
