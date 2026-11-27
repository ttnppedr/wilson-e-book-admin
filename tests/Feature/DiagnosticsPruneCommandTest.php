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

    DiagnosticEvent::factory()->count(2)->create([
        'diagnostic_session_id' => $session->getKey(),
        'received_at' => now()->subDays($daysSinceLastUpload),
    ]);

    return $session;
};

test('sessions last uploaded more than three months ago are deleted with their events', function () use ($makeSession): void {
    // 三個月是 89–92 天，取兩側都不會落在邊界上的天數；精確邊界由下一支測試守。
    $expired = $makeSession(95);
    $kept = $makeSession(85);

    $this->artisan('diagnostics:prune')->assertSuccessful();

    $this->assertModelMissing($expired);
    $this->assertModelExists($kept);
    $this->assertSame(0, DiagnosticEvent::query()->where('diagnostic_session_id', $expired->getKey())->count());
    $this->assertSame(2, DiagnosticEvent::query()->where('diagnostic_session_id', $kept->getKey())->count());
});

test('the default retention period is three calendar months of server time', function () use ($makeSession): void {
    $this->travelTo('2026-05-31 03:00:00');

    // 截止點是 2026-02-28 03:00（三個月前，月底不溢位到三月）。
    $expired = $makeSession(0, ['last_received_at' => '2026-02-28 02:59:59']);
    $kept = $makeSession(0, ['last_received_at' => '2026-02-28 03:00:01']);
    DiagnosticEvent::query()->update(['received_at' => now()]);

    $this->artisan('diagnostics:prune')->assertSuccessful();

    $this->assertModelMissing($expired);
    $this->assertModelExists($kept);
});

test('expired events are removed from a session that is still uploading and its counters follow', function (): void {
    // 白板好幾個月沒關：同一個 session，舊事件早已過期，新事件還在保留期內。
    $session = DiagnosticSession::factory()->create([
        'last_received_at' => now()->subDay(),
        'event_count' => 3,
        'warn_count' => 1,
        'error_count' => 1,
        'last_error_name' => 'old_error',
    ]);
    $event = fn (int $seq, string $level, int $daysAgo, string $name = 'e') => DiagnosticEvent::factory()->create([
        'diagnostic_session_id' => $session->getKey(),
        'seq' => $seq,
        'level' => $level,
        'name' => $name,
        'received_at' => now()->subDays($daysAgo),
    ]);
    $event(0, 'error', 120, 'old_error');
    $event(1, 'warn', 120);
    $recent = $event(2, 'info', 1);

    $this->artisan('diagnostics:prune')->assertSuccessful();

    $this->assertSame([$recent->getKey()], DiagnosticEvent::query()->pluck('id')->all());
    $session->refresh();
    $this->assertSame(1, $session->event_count);
    $this->assertSame(0, $session->warn_count);
    $this->assertSame(0, $session->error_count);
    $this->assertNull($session->last_error_name);
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

test('prune is scheduled in the middle of the night', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains((string) $event->command, 'diagnostics:prune'));

    $this->assertNotNull($event, 'diagnostics:prune 沒有排進排程');
    $this->assertSame('0 3 * * *', $event->expression);
});
