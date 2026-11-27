<?php

namespace Database\Factories;

use App\Models\DiagnosticSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiagnosticSession>
 */
class DiagnosticSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = now()->subMinutes(10);

        return [
            'session_uuid' => bin2hex(random_bytes(16)),
            'fingerprint' => bin2hex(random_bytes(8)),
            'app_version' => '1.1.4+10',
            'app_env' => 'staging',
            'platform' => 'android',
            'os_version' => '13',
            'device_model' => 'MeetBoard_4',
            'started_at' => $startedAt,
            'first_event_at' => $startedAt,
            'last_event_at' => $startedAt->copy()->addMinutes(5),
            'event_count' => 0,
            'warn_count' => 0,
            'error_count' => 0,
        ];
    }
}
