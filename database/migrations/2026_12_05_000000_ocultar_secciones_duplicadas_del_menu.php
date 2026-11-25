<?php

use App\Models\Seccion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Migrations\Migration;

/**
 * "Rol de vacaciones" y "Rol horarios" nacieron como secciones del menú y hoy son módulos con pantalla propia:
 * se ocultan las secciones repetidas SOLO si están vacías. No se borra nada y se pueden volver a mostrar desde
 * "Administrar menú". Una sección con registros, documentos, enlaces, nube o subsecciones se queda como está.
 */
return new class extends Migration
{
    private const DUPLICADAS = ['rol-de-vacaciones', 'rol-horarios'];

    public function up(): void
    {
        $this->vacias()->where('is_visible', true)->get()
            ->each(fn (Seccion $seccion) => $seccion->update(['is_visible' => false]));
    }

    public function down(): void
    {
        $this->vacias()->where('is_visible', false)->get()
            ->each(fn (Seccion $seccion) => $seccion->update(['is_visible' => true]));
    }

    /** @return Builder<Seccion> */
    private function vacias(): Builder
    {
        return Seccion::query()
            ->whereIn('slug', self::DUPLICADAS)
            ->whereDoesntHave('filas')
            ->whereDoesntHave('archivos')
            ->whereDoesntHave('enlaces')
            ->whereDoesntHave('origenes')
            ->whereDoesntHave('hijas');
    }
};
