<?php

namespace Database\Factories;

use App\Enums\ExampleStatus;
use App\Models\Example;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Example>
 */
class ExampleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'value' => fake()->word(),
            'status' => fake()->randomElement(ExampleStatus::cases())->value,
        ];
    }
}
