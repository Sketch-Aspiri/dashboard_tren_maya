<?php

namespace Database\Factories;

use App\Enums\EmpleadoEstatus;
use App\Models\Empleado;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Dummy data only (per .claude/rules/testing.md "Dummy Data" section) —
 * never real employee PII from the Jefe de Zona roster.
 *
 * @extends Factory<Empleado>
 */
class EmpleadoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'no_empleado' => fake()->unique()->numerify('EMP-#####'),
            'estatus' => EmpleadoEstatus::Activo->value,
            'estacion_codigo' => fake()->numerify('EST-##'),
            'plaza_actual' => fake()->numerify('PZ-###'),
            'nombre_completo' => fake()->name(),
            'puesto' => fake()->jobTitle(),
            'nivel_plaza' => fake()->randomElement(['M11', 'M22', 'M33', '11', '22']),
            'ultimo_grado_estudios' => fake()->randomElement(['Licenciatura', 'Preparatoria', 'Maestría', 'Secundaria']),
            'titulo' => fake()->boolean() ? fake()->jobTitle() : null,
            'cedula' => fake()->boolean() ? (string) fake()->numerify('########') : null,
            'fecha_ingreso' => fake()->dateTimeBetween('-8 years', '-1 month')->format('Y-m-d'),
            'telefono' => fake()->numerify('##########'),
            'correo' => fake()->boolean(80) ? fake()->safeEmail() : null,
            'tipo_sangre' => fake()->randomElement(['O+', 'O-', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-']),
            'alergias' => fake()->boolean(30) ? fake()->words(3, true) : null,
            'fecha_nacimiento' => fake()->dateTimeBetween('-60 years', '-20 years')->format('Y-m-d'),
            'lugar_nacimiento' => fake()->city(),
            'estado_civil' => fake()->randomElement(['Soltero', 'Casado', 'Unión libre', 'Divorciado']),
            'curp' => strtoupper(fake()->bothify('????######??????##')),
            'rfc' => strtoupper(fake()->bothify('????######???')),
            'nss' => fake()->numerify('###########'),
            'domicilio' => fake()->address(),
            'contacto_emergencia_nombre' => fake()->name(),
            'contacto_emergencia_telefono' => fake()->numerify('##########'),
            'desempeno' => fake()->boolean(20) ? fake()->sentence() : null,
        ];
    }

    /**
     * A vacant position on the roster — no person attached.
     */
    public function vacante(): static
    {
        return $this->state(fn (array $attributes) => [
            'no_empleado' => null,
            'estatus' => EmpleadoEstatus::Vacante->value,
            'nombre_completo' => null,
            'telefono' => null,
            'correo' => null,
            'tipo_sangre' => null,
            'alergias' => null,
            'fecha_nacimiento' => null,
            'lugar_nacimiento' => null,
            'estado_civil' => null,
            'curp' => null,
            'rfc' => null,
            'nss' => null,
            'domicilio' => null,
            'contacto_emergencia_nombre' => null,
            'contacto_emergencia_telefono' => null,
        ]);
    }
}
