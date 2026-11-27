<?php

namespace Database\Factories;

use App\Models\DiagnosticEvent;
use App\Models\DiagnosticSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiagnosticEvent>
 */
class DiagnosticEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'diagnostic_session_id' => DiagnosticSession::factory(),
            'seq' => fake()->unique()->numberBetween(0, 1_000_000),
            'occurred_at' => now(),
            'category' => 'app',
            'name' => 'start',
            'level' => 'info',
            'data' => null,
            'received_at' => now(),
        ];
    }
}
