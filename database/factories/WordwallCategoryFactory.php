<?php

namespace Database\Factories;

use App\Models\WordwallCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WordwallCategory>
 */
class WordwallCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'image_path' => 'wordwall-categories/'.fake()->uuid().'.png',
            'sort' => fake()->numberBetween(0, 100),
        ];
    }
}
