<?php

use App\Models\DiagnosticEvent;
use App\Models\DiagnosticSession;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $attributes
 */
$makeSession = function (int $daysSinceLastUpload, array $attributes = []): DiagnosticSession {
    $session = DiagnosticSession::factory()->create([
        'last_received_at' => now()->subDays($daysSinceLastUpload),
        ...$attributes,
    ]);

    DiagnosticEvent::factory()->count(2)->create(['diagnostic_session_id' => $session->getKey()]);

    return $session;
};

test('sessions last uploaded more than three months ago are deleted with their events', function () use ($makeSession): void {
    $expired = $makeSession(91);
    $kept = $makeSession(89);

    $this->artisan('diagnostics:prune')->assertSuccessful();

    $this->assertModelMissing($expired);
    $this->assertModelExists($kept);
    $this->assertSame(0, DiagnosticEvent::query()->where('diagnostic_session_id', $expired->getKey())->count());
    $this->assertSame(2, DiagnosticEvent::query()->where('diagnostic_session_id', $kept->getKey())->count());
});

test('retention follows the server receive time and ignores the device clock', function () use ($makeSession): void {
    // 裝置時鐘停在過去：昨天才上傳，不能因為事件時間是 2001 年就被清掉。
    $clockInThePast = $makeSession(1, ['last_event_at' => '2001-01-01 00:00:00']);
    // 裝置時鐘在未來：早就超過保留期，不能因為事件時間是 2099 年就永遠留著。
    $clockInTheFuture = $makeSession(120, ['last_event_at' => '2099-01-01 00:00:00']);

    $this->artisan('diagnostics:prune')->assertSuccessful();

    $this->assertModelExists($clockInThePast);
    $this->assertModelMissing($clockInTheFuture);
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
