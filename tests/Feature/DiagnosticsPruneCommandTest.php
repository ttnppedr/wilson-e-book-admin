<?php

use App\Models\DiagnosticEvent;
use App\Models\DiagnosticSession;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

$makeSession = function (int $daysSinceLastEvent): DiagnosticSession {
    $session = DiagnosticSession::factory()->create([
        'last_event_at' => now()->subDays($daysSinceLastEvent),
    ]);

    DiagnosticEvent::factory()->count(2)->create(['diagnostic_session_id' => $session->getKey()]);

    return $session;
};

test('sessions whose last event is older than 30 days are deleted with their events', function () use ($makeSession): void {
    $expired = $makeSession(31);
    $kept = $makeSession(29);

    $this->artisan('diagnostics:prune')->assertSuccessful();

    $this->assertModelMissing($expired);
    $this->assertModelExists($kept);
    $this->assertSame(0, DiagnosticEvent::query()->where('diagnostic_session_id', $expired->getKey())->count());
    $this->assertSame(2, DiagnosticEvent::query()->where('diagnostic_session_id', $kept->getKey())->count());
});

test('the retention period can be changed with the days option', function () use ($makeSession): void {
    $expired = $makeSession(8);
    $kept = $makeSession(6);

    $this->artisan('diagnostics:prune', ['--days' => 7])->assertSuccessful();

    $this->assertModelMissing($expired);
    $this->assertModelExists($kept);
});

test('a non positive retention period is refused and nothing is deleted', function (string $days) use ($makeSession): void {
    $session = $makeSession(400);

    $this->artisan('diagnostics:prune', ['--days' => $days])->assertFailed();

    $this->assertModelExists($session);
})->with(['0', '-5', 'abc']);

test('prune is scheduled daily', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains((string) $event->command, 'diagnostics:prune'));

    $this->assertNotNull($event, 'diagnostics:prune 沒有排進排程');
    $this->assertSame('0 3 * * *', $event->expression);
});
