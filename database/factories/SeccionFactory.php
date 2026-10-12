<?php

namespace Database\Factories;

use App\Models\MenuGrupo;
use App\Models\Seccion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Seccion>
 */
class SeccionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $nombre = 'Sección '.$this->faker->unique()->numberBetween(1, 999999);

        return [
            'menu_grupo_id' => fn () => MenuGrupo::query()->create([
                'nombre' => 'Grupo '.Str::random(6),
                'slug' => 'grupo-'.Str::lower(Str::random(10)),
                'orden' => 1,
            ])->id,
            'nombre' => $nombre,
            'slug' => Str::slug($nombre),
            'descripcion' => null,
            'orden' => 1,
            'is_visible' => true,
        ];
    }

    public function oculta(): static
    {
        return $this->state(['is_visible' => false]);
    }
}
