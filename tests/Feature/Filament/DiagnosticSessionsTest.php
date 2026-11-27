<?php

/**
 * 後台「診斷紀錄」：session 清單與時間軸檢視頁。
 *
 * 事件內容由裝置經免驗證端點上傳，檢視頁的 escape 是這裡最重要的一條。
 */

use App\Filament\Resources\DiagnosticSessions\DiagnosticSessionResource;
use App\Filament\Resources\DiagnosticSessions\Pages\ListDiagnosticSessions;
use App\Filament\Resources\DiagnosticSessions\Pages\ViewDiagnosticSession;
use App\Filament\Resources\LicenseResource;
use App\Filament\Resources\LicenseResource\Pages\ViewLicense;
use App\Filament\Resources\LicenseUsageResource\Pages\ViewLicenseUsage;
use App\Models\DiagnosticEvent;
use App\Models\DiagnosticSession;
use App\Models\License;
use App\Models\LicenseUsage;
use App\Models\User;
use App\Services\DiagnosticTimelineBuilder;
use Filament\Actions\DeleteAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Enums\UsageStatus;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

$makeLicense = fn (string $name): License => License::create([
    'key_hash' => License::hashKey('DIAGUI'.bin2hex(random_bytes(6))),
    'status' => LicenseStatus::Active,
    'name' => $name,
    'activated_at' => now(),
    'expires_at' => now()->addDays(30),
    'max_usages' => 1,
    'meta' => [],
]);

/**
 * Eloquent 寫入時間時不做時區轉換（直接取 Carbon 的牆上時間），所以測試資料要先
 * 轉成 app timezone，與 DiagnosticRecorder 寫入時的做法一致。
 */
$at = fn (string $time, string $timezone = 'UTC'): Carbon => Carbon::parse($time, $timezone)
    ->setTimezone(config('app.timezone'));

/**
 * @param  list<array<string, mixed>>  $events  每筆至少含 seq
 */
$addEvents = function (DiagnosticSession $session, array $events): void {
    foreach ($events as $event) {
        DiagnosticEvent::factory()->create(['diagnostic_session_id' => $session->getKey(), ...$event]);
    }
};

test('list shows sessions newest first with their license', function () use ($makeLicense, $at): void {
    $license = $makeLicense('光復國小');
    $older = DiagnosticSession::factory()->create([
        'license_id' => $license->getKey(),
        // 裝置時鐘在未來：排序看的是伺服器收到的時間，不能因此排到最前面。
        'last_event_at' => $at('2099-01-01 00:00:00'),
        'last_received_at' => $at('2026-11-26 23:00:00'),
    ]);
    $newer = DiagnosticSession::factory()->create([
        'license_id' => $license->getKey(),
        'first_event_at' => $at('2026-11-27 00:55:00'),
        'last_event_at' => $at('2026-11-27 01:05:30'),
        'last_received_at' => $at('2026-11-27 01:06:00'),
        'error_count' => 3,
        'last_error_name' => 'content_load_failed',
    ]);
    $this->get(DiagnosticSessionResource::getUrl('index'))->assertOk();

    Livewire::test(ListDiagnosticSessions::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$newer, $older], inOrder: true)
        ->assertSee('光復國小')
        ->assertSee('content_load_failed')
        // 時間以台灣時間顯示（UTC+8）。
        ->assertSee('2026-11-27 09:05:30')
        ->assertSee('2026-11-27 09:06:00')
        ->assertSee('10 分 30 秒')
        ->assertSee(LicenseResource::getUrl('view', ['record' => $license->getKey()]));
});

test('list loads licenses with a single query', function () use ($makeLicense): void {
    foreach (range(1, 5) as $index) {
        DiagnosticSession::factory()->create(['license_id' => $makeLicense("License {$index}")->getKey()]);
    }

    DB::enableQueryLog();
    Livewire::test(ListDiagnosticSessions::class)->assertOk();
    $licenseQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains($query['query'], 'from "licenses"'));
    DB::disableQueryLog();

    $this->assertCount(1, $licenseQueries, '授權應以 eager load 一次取回，不可逐列查詢');
});

test('list can be searched and filtered', function () use ($makeLicense, $at): void {
    $schoolA = $makeLicense('光復國小');
    $schoolB = $makeLicense('中正國小');
    $withErrors = DiagnosticSession::factory()->create([
        'license_id' => $schoolA->getKey(),
        'fingerprint' => 'aaaaaaaaaaaaaaaa',
        'app_env' => 'prod',
        'error_count' => 2,
        'last_event_at' => $at('2026-11-20 10:00:00', 'Asia/Taipei'),
    ]);
    $clean = DiagnosticSession::factory()->create([
        'license_id' => $schoolB->getKey(),
        'fingerprint' => 'bbbbbbbbbbbbbbbb',
        'app_env' => 'staging',
        'error_count' => 0,
        // 台灣時間 11-22 00:30，UTC 仍是 11-21：日期篩選必須以台灣時間的「日」為準。
        'last_event_at' => $at('2026-11-22 00:30:00', 'Asia/Taipei'),
    ]);

    Livewire::test(ListDiagnosticSessions::class)
        ->searchTable('aaaaaaaa')
        ->assertCanSeeTableRecords([$withErrors])
        ->assertCanNotSeeTableRecords([$clean])
        ->searchTable('中正')
        ->assertCanSeeTableRecords([$clean])
        ->assertCanNotSeeTableRecords([$withErrors])
        ->searchTable(null)
        ->filterTable('has_errors')
        ->assertCanSeeTableRecords([$withErrors])
        ->assertCanNotSeeTableRecords([$clean])
        ->resetTableFilters()
        ->filterTable('app_env', 'staging')
        ->assertCanSeeTableRecords([$clean])
        ->assertCanNotSeeTableRecords([$withErrors])
        ->resetTableFilters()
        ->filterTable('license', $schoolA->getKey())
        ->assertCanSeeTableRecords([$withErrors])
        ->assertCanNotSeeTableRecords([$clean])
        ->resetTableFilters()
        ->filterTable('last_event_at', ['from' => '2026-11-22', 'until' => '2026-11-22'])
        ->assertCanSeeTableRecords([$clean])
        ->assertCanNotSeeTableRecords([$withErrors])
        ->resetTableFilters()
        ->filterTable('last_event_at', ['until' => '2026-11-21'])
        ->assertCanSeeTableRecords([$withErrors])
        ->assertCanNotSeeTableRecords([$clean]);
});

test('list is read only but a session can be deleted together with its events', function () use ($addEvents): void {
    $session = DiagnosticSession::factory()->create();
    $addEvents($session, [['seq' => 1], ['seq' => 2]]);
    $other = DiagnosticSession::factory()->create();
    $addEvents($other, [['seq' => 1]]);

    $this->assertFalse(DiagnosticSessionResource::canCreate());
    $this->assertFalse(DiagnosticSessionResource::canEdit($session));
    $this->assertArrayNotHasKey('create', DiagnosticSessionResource::getPages());
    $this->assertArrayNotHasKey('edit', DiagnosticSessionResource::getPages());

    Livewire::test(ListDiagnosticSessions::class)
        ->callTableAction(DeleteAction::class, $session);

    $this->assertModelMissing($session);
    $this->assertSame(0, DiagnosticEvent::query()->where('diagnostic_session_id', $session->getKey())->count());
    $this->assertSame(1, DiagnosticEvent::query()->where('diagnostic_session_id', $other->getKey())->count());
});

test('view page renders the summary and the timeline', function () use ($makeLicense, $addEvents, $at): void {
    $license = $makeLicense('光復國小');
    $session = DiagnosticSession::factory()->create([
        'license_id' => $license->getKey(),
        'fingerprint' => '9312d107016e2503',
        'app_version' => '1.1.4+10',
        'app_env' => 'staging',
        'device_model' => 'MeetBoard_4',
        'event_count' => 5,
        'warn_count' => 1,
        'error_count' => 1,
    ]);
    $addEvents($session, [
        ['seq' => 1, 'occurred_at' => $at('2026-11-27T00:55:10.123Z'), 'category' => 'app', 'name' => 'start'],
        ['seq' => 2, 'occurred_at' => $at('2026-11-27T00:55:12.423Z'), 'category' => 'nav', 'name' => 'page_open', 'data' => ['page' => 'home', 'unit' => 3]],
        ['seq' => 3, 'occurred_at' => $at('2026-11-27T00:55:12.923Z'), 'category' => 'auth', 'name' => 'state', 'level' => 'warn', 'data' => ['from' => 'authenticated', 'to' => 'authenticating']],
        ['seq' => 4, 'occurred_at' => $at('2026-11-27T00:59:24.923Z'), 'category' => 'error', 'name' => 'content_load_failed', 'level' => 'error'],
        ['seq' => 5, 'occurred_at' => $at('2026-11-27T00:59:25.000Z'), 'category' => 'future_cat', 'name' => 'something_new'],
    ]);

    $this->get(DiagnosticSessionResource::getUrl('view', ['record' => $session]))->assertOk();

    Livewire::test(ViewDiagnosticSession::class, ['record' => $session->getKey()])
        ->assertOk()
        // 摘要
        ->assertSee('光復國小')
        ->assertSee('9312d107016e2503')
        ->assertSee('1.1.4+10／staging')
        ->assertSee('MeetBoard_4')
        // 時間軸：台灣時間含毫秒、與上一筆的間隔、類別中文標籤、data 重點
        ->assertSee('08:55:10.123')
        ->assertSee('+2.3s')
        ->assertSee('+500ms')
        ->assertSee('+4m12s')
        ->assertSeeInOrder(['應用程式', '頁面', '授權', '錯誤'])
        ->assertSeeInOrder(['page=', 'home', 'unit=', '3'])
        ->assertSee('content_load_failed')
        // 間隔 ≥ 60 秒時在兩筆之間插入分隔線
        ->assertSeeInOrder(['data-level="warn"', '間隔 4 分 12 秒', 'data-level="error"'], false)
        // 未知類別用預設樣式、顯示原名
        ->assertSee('diag-cat-other', false)
        ->assertSee('future_cat')
        ->assertSee('跳到第一個錯誤');
});

test('event content from the device is escaped on the view page', function () use ($addEvents): void {
    $session = DiagnosticSession::factory()->create([
        'device_model' => '<script>alert("model")</script>',
        'last_error_name' => '<script>alert("last")</script>',
    ]);
    $addEvents($session, [[
        'seq' => 1,
        'category' => 'nav',
        'name' => '<script>alert("name")</script>',
        'level' => 'error',
        'data' => [
            '<img src=x onerror=alert("key")>' => '<script>alert("value")</script>',
            'nested' => ['html' => '</pre><script>alert("nested")</script>'],
        ],
    ]]);

    $html = Livewire::test(ViewDiagnosticSession::class, ['record' => $session->getKey()])
        ->assertOk()
        ->html();

    foreach (['model', 'last', 'name', 'key', 'value', 'nested'] as $marker) {
        $this->assertStringNotContainsString("alert(\"{$marker}\")", $html, "{$marker} 未經 escape 就輸出");
    }
    $this->assertStringNotContainsString('<img src=x', $html);
    $this->assertStringContainsString('&lt;script&gt;alert(&quot;name&quot;)&lt;/script&gt;', $html);
    $this->assertStringContainsString('&lt;script&gt;alert(&quot;value&quot;)&lt;/script&gt;', $html);
    $this->assertStringContainsString('&lt;img src=x onerror=alert(&quot;key&quot;)&gt;', $html);
});

// App 把既有的內部 log 以固定名稱 `trace` 上傳，訊息在 data.msg、例外在 data.error。
test('trace events are titled by their message and show the nested error inline', function () use ($addEvents): void {
    $session = DiagnosticSession::factory()->create();
    $addEvents($session, [
        [
            'seq' => 0,
            'category' => 'content',
            'name' => 'trace',
            'level' => 'error',
            'data' => [
                'src' => 'AuthProvider',
                'msg' => '教材資料表載入失敗（C3）',
                'error' => ['kind' => 'file_system', 'os_error' => 5, 'message' => 'FileSystemException: read failed'],
            ],
        ],
        ['seq' => 1, 'category' => 'auth', 'name' => 'state', 'data' => ['msg' => '不是 trace 就不改標題']],
    ]);

    $rows = collect(app(DiagnosticTimelineBuilder::class)->build($session, 100)['rows'])
        ->where('type', 'event')
        ->values();

    $this->assertSame('教材資料表載入失敗（C3）', $rows[0]['name']);
    $summary = collect($rows[0]['summary'])->pluck('value', 'key')->all();
    $this->assertArrayNotHasKey('msg', $summary);
    $this->assertSame('file_system', $summary['error.kind']);
    $this->assertSame('5', $summary['error.os_error']);

    $this->assertSame('state', $rows[1]['name']);
    $this->assertSame('不是 trace 就不改標題', collect($rows[1]['summary'])->pluck('value', 'key')->get('msg'));

    Livewire::test(ViewDiagnosticSession::class, ['record' => $session->getKey()])
        ->assertSee('教材資料表載入失敗（C3）')
        ->assertSee('error.kind');
});

test('a category that slipped past validation cannot reach a css selector', function () use ($addEvents): void {
    $session = DiagnosticSession::factory()->create();
    $addEvents($session, [['seq' => 1, 'category' => "x'] { } </style><script>alert(1)</script>"]]);

    $html = Livewire::test(ViewDiagnosticSession::class, ['record' => $session->getKey()])
        ->assertOk()
        ->html();

    $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    $this->assertStringContainsString('data-cat="other"', $html);
});

test('a long session loads the newest events first and earlier ones on demand', function (): void {
    $session = DiagnosticSession::factory()->create();
    $total = DiagnosticTimelineBuilder::PAGE_SIZE + 5;
    $base = Carbon::parse('2026-11-27 00:00:00');

    DiagnosticEvent::query()->insert(array_map(fn (int $seq): array => [
        'diagnostic_session_id' => $session->getKey(),
        'seq' => $seq,
        'occurred_at' => $base->copy()->addSeconds($seq)->format('Y-m-d H:i:s.v'),
        'category' => 'tap',
        'name' => 'tap',
        'level' => 'info',
        'data' => null,
        'received_at' => $base->format('Y-m-d H:i:s.v'),
    ], range(1, $total)));

    Livewire::test(ViewDiagnosticSession::class, ['record' => $session->getKey()])
        ->assertOk()
        ->assertSee('顯示最新 2,000 筆／共 2,005 筆事件')
        ->assertSee('載入更早的 5 筆')
        ->assertSee('diag-event-2005"', false)
        ->assertSee('diag-event-6"', false)
        ->assertDontSee('diag-event-5"', false)
        ->call('loadEarlier')
        ->assertSee('diag-event-1"', false)
        ->assertSee('共 2,005 筆事件')
        ->assertDontSee('載入更早的');
});

test('license and usage pages link to the filtered diagnostics list', function () use ($makeLicense): void {
    $license = $makeLicense('光復國小');
    $usage = LicenseUsage::create([
        'license_id' => $license->getKey(),
        'usage_fingerprint' => '9312d107016e2503',
        'status' => UsageStatus::Active,
        'registered_at' => now(),
        'last_seen_at' => now(),
        'client_type' => 'android',
        'meta' => [],
    ]);
    $thisDevice = DiagnosticSession::factory()->create([
        'license_id' => $license->getKey(),
        'fingerprint' => '9312d107016e2503',
    ]);
    $otherDevice = DiagnosticSession::factory()->create([
        'license_id' => $makeLicense('中正國小')->getKey(),
        'fingerprint' => 'ffffffffffffffff',
    ]);

    $byFingerprint = ['filters' => ['fingerprint' => ['value' => '9312d107016e2503']]];
    $byLicense = ['filters' => ['license' => ['value' => $license->getKey()]]];

    Livewire::test(ViewLicenseUsage::class, ['record' => $usage->getKey()])
        ->assertActionHasUrl('diagnostics', DiagnosticSessionResource::getUrl('index', $byFingerprint));

    Livewire::test(ViewLicense::class, ['record' => $license->getKey()])
        ->assertActionHasUrl('diagnostics', DiagnosticSessionResource::getUrl('index', $byLicense));

    // 連結帶的 query string 確實會套用成篩選。
    foreach ([$byFingerprint, $byLicense] as $query) {
        Livewire::withQueryParams($query)
            ->test(ListDiagnosticSessions::class)
            ->assertCanSeeTableRecords([$thisDevice])
            ->assertCanNotSeeTableRecords([$otherDevice]);
    }
});
