<?php

/**
 * `POST /api/licensing/v1/diagnostics`：App 上傳操作事件日誌。
 *
 * 這個端點**免驗證**，所以除了正常寫入之外，重點在三件事：
 *   - 只保存指紋對得上既有席位的資料（否則是任意寫入口）。
 *   - 重送同一批不重複寫入（App 會重試）。
 *   - 絕不回 401／404（App 對某些 401／404 會清除本機授權）。
 */

use App\Models\DiagnosticEvent;
use App\Models\DiagnosticSession;
use App\Models\License;
use App\Models\LicenseUsage;
use App\Services\DiagnosticRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Enums\UsageStatus;

uses(RefreshDatabase::class);

$url = '/api/licensing/v1/diagnostics';
$fingerprint = '9312d107016e2503';
$sessionId = '0123456789abcdef0123456789abcdef';

beforeEach(function (): void {
    Cache::flush();
});

$makeUsage = function (string $fingerprint, array $usageAttributes = []): LicenseUsage {
    $license = License::create([
        'key_hash' => License::hashKey('DIAG'.bin2hex(random_bytes(6))),
        'status' => LicenseStatus::Active,
        'name' => 'Diagnostics School',
        'activated_at' => now(),
        'expires_at' => now()->addDays(30),
        'max_usages' => 1,
        'meta' => [],
    ]);

    return LicenseUsage::create(array_merge([
        'license_id' => $license->getKey(),
        'usage_fingerprint' => $fingerprint,
        'status' => UsageStatus::Active,
        'registered_at' => now(),
        'last_seen_at' => now(),
        'client_type' => 'android',
        'meta' => [],
    ], $usageAttributes));
};

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
$event = fn (int $seq, array $overrides = []): array => array_merge([
    'seq' => $seq,
    't' => sprintf('2026-11-27T00:59:%02d.123Z', $seq % 60),
    'cat' => 'nav',
    'name' => 'page_open',
    'level' => 'info',
    'data' => ['page' => 'home'],
], $overrides);

/**
 * @param  list<array<string, mixed>>  $events
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
$payload = fn (array $events, array $overrides = []): array => array_merge([
    'fingerprint' => $fingerprint,
    'session_id' => $sessionId,
    'started_at' => '2026-11-27T00:55:10.123Z',
    'app' => [
        'version' => '1.1.4+10',
        'env' => 'staging',
        'platform' => 'android',
        'os_version' => '13',
        'model' => 'MeetBoard_4',
    ],
    'events' => $events,
], $overrides);

test('stores the session and its events and links them to the known device', function () use ($url, $fingerprint, $sessionId, $makeUsage, $event, $payload): void {
    $usage = $makeUsage($fingerprint);

    $response = $this->postJson($url, $payload([
        $event(1, ['cat' => 'app', 'name' => 'start', 'data' => null]),
        $event(2, ['cat' => 'auth', 'name' => 'state', 'level' => 'warn', 'data' => ['from' => 'authenticated', 'to' => 'authenticating']]),
        $event(3, ['cat' => 'error', 'name' => 'content_load_failed', 'level' => 'error']),
    ]));

    $response->assertStatus(202);
    $response->assertExactJson(['success' => true, 'accepted' => 3, 'recognized' => true]);

    $session = DiagnosticSession::query()->sole();
    $this->assertSame($sessionId, $session->session_uuid);
    $this->assertSame($fingerprint, $session->fingerprint);
    $this->assertSame($usage->getKey(), $session->license_usage_id);
    $this->assertSame($usage->license_id, $session->license_id);
    $this->assertSame('1.1.4+10', $session->app_version);
    $this->assertSame('staging', $session->app_env);
    $this->assertSame('MeetBoard_4', $session->device_model);
    $this->assertSame(3, $session->event_count);
    $this->assertSame(1, $session->warn_count);
    $this->assertSame(1, $session->error_count);
    $this->assertSame('content_load_failed', $session->last_error_name);

    // 毫秒必須保留，且以 UTC 解讀 App 送來的時間。
    $this->assertSame('2026-11-27T00:55:10.123Z', $session->started_at->utc()->format('Y-m-d\TH:i:s.v\Z'));
    $this->assertSame('2026-11-27T00:59:01.123Z', $session->first_event_at->utc()->format('Y-m-d\TH:i:s.v\Z'));
    $this->assertSame('2026-11-27T00:59:03.123Z', $session->last_event_at->utc()->format('Y-m-d\TH:i:s.v\Z'));

    $stored = $session->events()->orderBy('seq')->get();
    $this->assertSame([1, 2, 3], $stored->pluck('seq')->all());
    $this->assertSame('auth', $stored[1]->category);
    $this->assertSame(['from' => 'authenticated', 'to' => 'authenticating'], $stored[1]->data);
    $this->assertNull($stored[0]->data);
});

test('event times keep the same instant whatever the app timezone is', function (string $timezone, string $storedAs) use ($url, $fingerprint, $makeUsage, $event, $payload): void {
    // Eloquent 以 app timezone 解讀時間欄位、寫入時又不做轉換，所以 App 送來的 UTC
    // 必須先轉成 app timezone 再存，否則在 Asia/Taipei（本專案的設定）下會差 8 小時。
    $originalTimezone = date_default_timezone_get();
    config(['app.timezone' => $timezone]);
    date_default_timezone_set($timezone);

    try {
        $makeUsage($fingerprint);

        $this->postJson($url, $payload([$event(1)]))->assertStatus(202);

        $stored = DiagnosticEvent::query()->sole();
        $this->assertSame($storedAs, $stored->getRawOriginal('occurred_at'));
        $this->assertSame('2026-11-27T00:59:01.123Z', $stored->occurred_at->utc()->format('Y-m-d\TH:i:s.v\Z'));
    } finally {
        date_default_timezone_set($originalTimezone);
    }
})->with([
    'utc' => ['UTC', '2026-11-27 00:59:01.123'],
    'taipei' => ['Asia/Taipei', '2026-11-27 08:59:01.123'],
]);

test('resending the same batch does not write duplicates or inflate the counters', function () use ($url, $fingerprint, $makeUsage, $event, $payload): void {
    $makeUsage($fingerprint);
    $batch = $payload([$event(1), $event(2, ['level' => 'error', 'name' => 'boom'])]);

    $this->postJson($url, $batch)->assertStatus(202)->assertJsonPath('accepted', 2);
    // 整批重送與「指紋不認得」同樣是 accepted 0，App 只能靠 recognized 分辨。
    $this->postJson($url, $batch)->assertStatus(202)->assertExactJson(['success' => true, 'accepted' => 0, 'recognized' => true]);

    // 部分重疊：只有 seq 3 是新的。
    $this->postJson($url, $payload([$event(2, ['level' => 'error', 'name' => 'boom']), $event(3)]))
        ->assertStatus(202)
        ->assertJsonPath('accepted', 1);

    $session = DiagnosticSession::query()->sole();
    $this->assertSame(3, DiagnosticEvent::query()->count());
    $this->assertSame(3, $session->event_count);
    $this->assertSame(1, $session->error_count);
});

test('an unknown fingerprint is answered with 202 but nothing is stored', function () use ($url, $event, $payload): void {
    $response = $this->postJson($url, $payload([$event(1)]));

    $response->assertStatus(202);
    $response->assertExactJson(['success' => true, 'accepted' => 0, 'recognized' => false]);
    $this->assertSame(0, DiagnosticSession::query()->count());
    $this->assertSame(0, DiagnosticEvent::query()->count());
});

test('a device whose usage was deleted is told it is no longer recognized', function () use ($url, $fingerprint, $makeUsage, $event, $payload): void {
    $usage = $makeUsage($fingerprint);
    $this->postJson($url, $payload([$event(1)]))->assertExactJson(['success' => true, 'accepted' => 1, 'recognized' => true]);

    $usage->delete();

    // 同一個 session 的下一批：App 必須知道沒人收下，不能把本機紀錄當成已送達刪掉。
    $this->postJson($url, $payload([$event(2)]))
        ->assertStatus(202)
        ->assertExactJson(['success' => true, 'accepted' => 0, 'recognized' => false]);
    $this->assertSame(1, DiagnosticEvent::query()->count());
});

test('another device cannot append events to an existing session', function () use ($url, $fingerprint, $makeUsage, $event, $payload): void {
    $makeUsage($fingerprint);
    $makeUsage('ffffffffffffffff');

    $this->postJson($url, $payload([$event(1)]))->assertJsonPath('accepted', 1);

    // 指紋本身認得，只是不屬於這個 session：不是「沒人收」，recognized 仍為 true。
    $this->postJson($url, $payload([$event(2)], ['fingerprint' => 'ffffffffffffffff']))
        ->assertStatus(202)
        ->assertExactJson(['success' => true, 'accepted' => 0, 'recognized' => true]);

    $this->assertSame(1, DiagnosticEvent::query()->count());
});

test('a fingerprint used by several licenses is linked to the most recently seen usage', function () use ($url, $fingerprint, $makeUsage, $event, $payload): void {
    $makeUsage($fingerprint, ['last_seen_at' => now()->subDays(5)]);
    $recent = $makeUsage($fingerprint, ['last_seen_at' => now()->subMinute()]);
    $makeUsage($fingerprint, ['last_seen_at' => now()->subDays(2)]);

    $this->postJson($url, $payload([$event(1)]))->assertStatus(202);

    $session = DiagnosticSession::query()->sole();
    $this->assertSame($recent->getKey(), $session->license_usage_id);
    $this->assertSame($recent->license_id, $session->license_id);
});

test('the app request without accept header and with a stray bearer is accepted', function () use ($url, $fingerprint, $makeUsage, $event, $payload): void {
    $makeUsage($fingerprint);

    // 比照 dart:io：只帶 Content-Type、不帶 Accept。Authorization 帶了也不驗。
    $response = $this->call('POST', $url, [], [], [], [
        'CONTENT_TYPE' => 'application/json; charset=utf-8',
        'HTTP_ACCEPT' => '',
        'HTTP_AUTHORIZATION' => 'Bearer not-a-valid-token',
    ], json_encode($payload([$event(1)]), JSON_THROW_ON_ERROR));

    $response->assertStatus(202);
    $response->assertExactJson(['success' => true, 'accepted' => 1, 'recognized' => true]);
});

test('optional fields may be omitted and an empty env is accepted', function () use ($url, $fingerprint, $makeUsage, $event, $payload): void {
    $makeUsage($fingerprint);
    $minimalEvent = $event(1);
    unset($minimalEvent['data']);

    $this->postJson($url, $payload([$minimalEvent], [
        'app' => ['version' => '1.1.4+10', 'env' => '', 'platform' => 'android'],
    ]))->assertStatus(202)->assertJsonPath('accepted', 1);

    $session = DiagnosticSession::query()->sole();
    $this->assertNull($session->app_env);
    $this->assertNull($session->os_version);
    $this->assertNull($session->device_model);
});

test('an unknown category is stored as is', function () use ($url, $fingerprint, $makeUsage, $event, $payload): void {
    $makeUsage($fingerprint);

    $this->postJson($url, $payload([$event(1, ['cat' => 'future_cat'])]))
        ->assertStatus(202)
        ->assertJsonPath('accepted', 1);

    $this->assertSame('future_cat', DiagnosticEvent::query()->sole()->category);
});

test('oversized event data is replaced by a truncation marker instead of rejecting the batch', function () use ($url, $fingerprint, $makeUsage, $event, $payload): void {
    $makeUsage($fingerprint);

    $this->postJson($url, $payload([
        $event(1, ['data' => ['blob' => str_repeat('x', DiagnosticRecorder::MAX_DATA_BYTES + 1)]]),
        $event(2, ['data' => ['page' => 'home']]),
    ]))->assertStatus(202)->assertJsonPath('accepted', 2);

    $stored = DiagnosticEvent::query()->orderBy('seq')->get();
    $this->assertSame(['_truncated' => true], $stored[0]->data);
    $this->assertSame(['page' => 'home'], $stored[1]->data);
});

dataset('invalid diagnostics payloads', [
    'missing fingerprint' => [['fingerprint' => null], 'fingerprint'],
    'fingerprint is not a string' => [['fingerprint' => ['a']], 'fingerprint'],
    'session id is not 32 lowercase hex' => [['session_id' => 'ABCDEF0123456789ABCDEF0123456789'], 'session_id'],
    'started_at is not iso8601' => [['started_at' => 'yesterday'], 'started_at'],
    'missing app version' => [['app' => ['platform' => 'android']], 'app.version'],
    'unknown env' => [['app' => ['version' => '1', 'env' => 'qa', 'platform' => 'android']], 'app.env'],
    'no events' => [['events' => []], 'events'],
    'category with illegal characters' => [['events' => [['seq' => 1, 't' => '2026-11-27T00:59:53.123Z', 'cat' => 'Nav<', 'name' => 'x', 'level' => 'info']]], 'events.0.cat'],
    'unknown level' => [['events' => [['seq' => 1, 't' => '2026-11-27T00:59:53.123Z', 'cat' => 'nav', 'name' => 'x', 'level' => 'fatal']]], 'events.0.level'],
    'negative seq' => [['events' => [['seq' => -1, 't' => '2026-11-27T00:59:53.123Z', 'cat' => 'nav', 'name' => 'x', 'level' => 'info']]], 'events.0.seq'],
    'name longer than 64' => [['events' => [['seq' => 1, 't' => '2026-11-27T00:59:53.123Z', 'cat' => 'nav', 'name' => 'nnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnn', 'level' => 'info']]], 'events.0.name'],
    'event time is not iso8601' => [['events' => [['seq' => 1, 't' => '2026-13-45T00:00:00Z', 'cat' => 'nav', 'name' => 'x', 'level' => 'info']]], 'events.0.t'],
    'data is not an object' => [['events' => [['seq' => 1, 't' => '2026-11-27T00:59:53.123Z', 'cat' => 'nav', 'name' => 'x', 'level' => 'info', 'data' => 'text']]], 'events.0.data'],
]);

test('an invalid payload is rejected with the unified 422 format', function (array $overrides, string $invalidField) use ($url, $fingerprint, $makeUsage, $event, $payload): void {
    $makeUsage($fingerprint);

    // 不帶 Accept：驗證失敗也必須是 JSON 422，不能變成 302 轉址。
    $response = $this->call('POST', $url, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => '',
    ], json_encode($payload([$event(1)], $overrides), JSON_THROW_ON_ERROR));

    $response->assertStatus(422);
    $response->assertJsonPath('success', false);
    $response->assertJsonPath('error.code', 'VALIDATION_FAILED');
    $this->assertArrayHasKey($invalidField, $response->json('error.details'));
    $this->assertSame(0, DiagnosticEvent::query()->count());
})->with('invalid diagnostics payloads');

test('more than 500 events are rejected', function () use ($url, $fingerprint, $makeUsage, $event, $payload): void {
    $makeUsage($fingerprint);
    $events = array_map(fn (int $seq): array => $event($seq), range(1, 501));

    $this->postJson($url, $payload(array_slice($events, 0, 500)))
        ->assertStatus(202)
        ->assertJsonPath('accepted', 500);

    $response = $this->postJson($url, $payload($events, ['session_id' => str_repeat('a', 32)]));

    $response->assertStatus(422);
    $response->assertJsonPath('error.code', 'VALIDATION_FAILED');
    $this->assertArrayHasKey('events', $response->json('error.details'));
    $this->assertSame(500, DiagnosticEvent::query()->count());
});

test('a body over the size cap is rejected without storing anything', function () use ($url, $fingerprint, $makeUsage, $event, $payload): void {
    $makeUsage($fingerprint);

    // 200 筆 × 約 3 KB：每筆 data 都在單筆上限內，但整包超過 512 KB。
    $events = array_map(
        fn (int $seq): array => $event($seq, ['data' => ['blob' => str_repeat('x', 3000)]]),
        range(1, 200),
    );

    $response = $this->postJson($url, $payload($events));

    $response->assertStatus(413);
    $response->assertJsonPath('error.code', 'PAYLOAD_TOO_LARGE');
    $this->assertSame(0, DiagnosticEvent::query()->count());
});

test('uploads are rate limited per ip and fingerprint', function () use ($url, $fingerprint, $event, $payload): void {
    config(['licensing.rate_limit.diagnostics_per_minute' => 3]);

    $post = fn (string $fp, int $seq): TestResponse => $this->postJson($url, $payload([$event($seq)], ['fingerprint' => $fp]));

    foreach (range(1, 3) as $seq) {
        $post($fingerprint, $seq)->assertStatus(202);
    }

    $blocked = $post($fingerprint, 4);
    $blocked->assertStatus(429);
    $blocked->assertJsonPath('error.code', 'RATE_LIMITED');
    $this->assertNotNull($blocked->headers->get('Retry-After'));

    // 複合鍵含指紋：別的裝置不受影響。
    $post('another-device', 1)->assertStatus(202);
});

test('an unknown fingerprint is answered before the events are validated', function () use ($url, $event, $payload): void {
    // 逐筆驗證事件是最貴的一步，陌生指紋不該付得起：事件內容無效也照樣回 202。
    $response = $this->postJson($url, $payload([$event(1, ['level' => 'fatal', 'cat' => 'NOT VALID'])]));

    $response->assertStatus(202);
    $response->assertExactJson(['success' => true, 'accepted' => 0, 'recognized' => false]);
});

test('the server receive time is kept next to the device time', function () use ($url, $fingerprint, $makeUsage, $event, $payload): void {
    $makeUsage($fingerprint);
    $this->travelTo('2026-11-27 12:00:00.500');

    // 裝置時鐘停在 2001 年。
    $this->postJson($url, $payload([$event(1, ['t' => '2001-01-01T00:00:05.000Z'])]))->assertJsonPath('accepted', 1);

    $session = DiagnosticSession::query()->sole();
    $this->assertSame('2001-01-01', $session->last_event_at->utc()->format('Y-m-d'));
    $this->assertSame(now()->format('Y-m-d H:i:s.v'), $session->last_received_at->format('Y-m-d H:i:s.v'));
    $this->assertSame(now()->format('Y-m-d H:i:s.v'), $session->events()->sole()->received_at->format('Y-m-d H:i:s.v'));

    $this->travelTo('2026-11-28 08:00:00');
    $this->postJson($url, $payload([$event(2, ['t' => '2001-01-01T00:00:06.000Z'])]))->assertJsonPath('accepted', 1);

    $this->assertSame('2026-11-28 08:00:00', $session->refresh()->last_received_at->format('Y-m-d H:i:s'));
});

test('every upload outcome is written to the api log with its reason', function () use ($url, $fingerprint, $sessionId, $makeUsage, $event, $payload): void {
    $logPath = storage_path('logs/testing-diagnostics-'.getmypid().'.log');
    config(['logging.channels.api' => ['driver' => 'single', 'path' => $logPath, 'level' => 'info']]);
    @unlink($logPath);

    $usage = $makeUsage($fingerprint);
    $makeUsage('ffffffffffffffff');

    $this->postJson($url, $payload([$event(1)], ['fingerprint' => 'aaaaaaaaaaaaaaaa']));
    $this->postJson($url, $payload([$event(1), $event(2, ['data' => ['blob' => str_repeat('x', DiagnosticRecorder::MAX_DATA_BYTES)]])]));
    $this->postJson($url, $payload([$event(1), $event(2), $event(3)]));
    $this->postJson($url, $payload([$event(1), $event(2)]));
    $this->postJson($url, $payload([$event(9)], ['fingerprint' => 'ffffffffffffffff']));

    $entries = [];
    foreach (explode("\n", trim((string) file_get_contents($logPath))) as $line) {
        if (str_contains($line, '[api] diagnostics upload')) {
            $entries[] = json_decode(substr($line, strpos($line, '{')), true, flags: JSON_THROW_ON_ERROR);
        }
    }
    @unlink($logPath);

    $this->assertSame(
        ['unknown_fingerprint', 'stored', 'stored', 'duplicate_only', 'fingerprint_mismatch'],
        array_column($entries, 'outcome'),
    );

    $this->assertSame([
        'outcome' => 'unknown_fingerprint',
        'session_id' => $sessionId,
        'fingerprint_prefix' => 'aaaaaaaa',
        'accepted' => 0,
        'received' => 1,
    ], $entries[0]);
    $this->assertSame([
        'outcome' => 'stored',
        'session_id' => $sessionId,
        'fingerprint_prefix' => '9312d107',
        'accepted' => 2,
        'diagnostic_session_id' => DiagnosticSession::query()->sole()->getKey(),
        'license_id' => $usage->license_id,
        'license_usage_id' => $usage->getKey(),
        'received' => 2,
        'seq_min' => 1,
        'seq_max' => 2,
        'duplicates' => 0,
        'truncated' => 1,
    ], $entries[1]);
    $this->assertSame([1, 2, 3], [$entries[2]['accepted'], $entries[2]['duplicates'], $entries[2]['received']]);
    $this->assertSame([0, 2], [$entries[3]['accepted'], $entries[3]['duplicates']]);
    $this->assertSame('9312d107', $entries[4]['bound_fingerprint_prefix']);
    $this->assertSame('ffffffff', $entries[4]['fingerprint_prefix']);
});
