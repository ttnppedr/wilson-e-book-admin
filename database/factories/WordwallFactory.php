<?php

namespace Database\Factories;

use App\Models\Wordwall;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Wordwall>
 */
class WordwallFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'resource_url' => 'https://wordwall.net/resource/'.fake()->unique()->numberBetween(100000, 999999),
            'sort' => fake()->numberBetween(0, 100),
        ];
    }
}
