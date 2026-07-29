<?php

use App\Models\License;
use App\Models\LicenseScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Events\LicenseExpired;
use OwenIt\Auditing\Models\Audit;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // 排程是系統自動執行（user_id 會是 null），但既有 audit 驗證
    // 預期有 actor，故仍建一位管理者登入保持測試 setup 一致。
    $this->actingAs(User::factory()->create());

    $this->scope = LicenseScope::create([
        'name' => 'Test Scope',
        'is_active' => true,
        'default_max_usages' => 3,
        'default_duration_days' => 365,
    ]);
});

$makeLicense = function (LicenseScope $scope, array $attributes): License {
    return License::create(array_merge([
        'key_hash' => hash('sha256', fake()->uuid()),
        'license_scope_id' => $scope->getKey(),
        'name' => 'Test License',
        'max_usages' => 3,
        'meta' => [],
    ], $attributes));
};

test('active license past expires at transitions directly to expired', function () use ($makeLicense): void {
    Event::fake([LicenseExpired::class]);

    $license = $makeLicense($this->scope, [
        'status' => LicenseStatus::Active,
        'activated_at' => now()->subDays(100),
        'expires_at' => now()->subDay(),
    ]);

    $this->artisan('licensing:check-expirations')->assertSuccessful();

    // 產品決策：無寬限期。不再經過 grace 中繼站。
    $this->assertSame(LicenseStatus::Expired, $license->refresh()->status);
    Event::assertDispatched(
        LicenseExpired::class,
        fn (LicenseExpired $event) => $event->license->is($license),
    );
});

test('license never enters grace status', function () use ($makeLicense): void {
    $license = $makeLicense($this->scope, [
        'status' => LicenseStatus::Active,
        'activated_at' => now()->subDays(100),
        'expires_at' => now()->subDay(),
    ]);

    // 直接呼叫也不得進入 Grace——覆寫在 model 層，vendor 自己的
    // CheckExpirationsCommand 呼叫這支時同樣走不進去。
    $license->transitionToGrace();

    $this->assertSame(LicenseStatus::Active, $license->refresh()->status);
});

test('legacy grace license is drained to expired', function () use ($makeLicense): void {
    Event::fake([LicenseExpired::class]);

    // 舊流程殘留的 grace 資料：不論當初的寬限窗多長，一律收斂為 expired。
    $license = $makeLicense($this->scope, [
        'status' => LicenseStatus::Grace,
        'activated_at' => now()->subDays(200),
        'expires_at' => now()->subDay(),
    ]);

    $this->artisan('licensing:check-expirations')->assertSuccessful();

    $this->assertSame(LicenseStatus::Expired, $license->refresh()->status);
    Event::assertDispatched(LicenseExpired::class);
});

test('active license not yet expired is untouched', function () use ($makeLicense): void {
    $license = $makeLicense($this->scope, [
        'status' => LicenseStatus::Active,
        'activated_at' => now()->subDay(),
        'expires_at' => now()->addDays(30),
    ]);

    $this->artisan('licensing:check-expirations')->assertSuccessful();

    $this->assertSame(LicenseStatus::Active, $license->refresh()->status);
});

/**
 * Pending 代表「建立後從未啟用」，不是管理者的處置決定——過期就該收成 expired，
 * 否則後台會把一張早已不可能啟用的授權持續算成「待啟用」。
 */
test('pending license past expires at transitions to expired', function () use ($makeLicense): void {
    Event::fake([LicenseExpired::class]);

    $license = $makeLicense($this->scope, [
        'status' => LicenseStatus::Pending,
        'activated_at' => null,
        'expires_at' => now()->subDay(),
    ]);

    $this->artisan('licensing:check-expirations')->assertSuccessful();

    $license->refresh();
    $this->assertSame(LicenseStatus::Expired, $license->status);
    // 從未啟用過，activated_at 必須維持 null——不能因為收斂狀態就補上假時間。
    $this->assertNull($license->activated_at);
    Event::assertDispatched(LicenseExpired::class);
});

test('suspended license with past expires at is untouched', function () use ($makeLicense): void {
    $license = $makeLicense($this->scope, [
        'status' => LicenseStatus::Suspended,
        'activated_at' => now()->subDays(100),
        'expires_at' => now()->subDay(),
    ]);

    $this->artisan('licensing:check-expirations')->assertSuccessful();

    $this->assertSame(LicenseStatus::Suspended, $license->refresh()->status);
});

test('cancelled license with past expires at is untouched', function () use ($makeLicense): void {
    $license = $makeLicense($this->scope, [
        'status' => LicenseStatus::Cancelled,
        'activated_at' => now()->subDays(100),
        'expires_at' => now()->subDay(),
    ]);

    $this->artisan('licensing:check-expirations')->assertSuccessful();

    $this->assertSame(LicenseStatus::Cancelled, $license->refresh()->status);
});

test('license without expires at is untouched', function () use ($makeLicense): void {
    $license = $makeLicense($this->scope, [
        'status' => LicenseStatus::Active,
        'activated_at' => now()->subDays(100),
        'expires_at' => null,
    ]);

    $this->artisan('licensing:check-expirations')->assertSuccessful();

    $this->assertSame(LicenseStatus::Active, $license->refresh()->status);
});

test('transition writes audit record', function () use ($makeLicense): void {
    $license = $makeLicense($this->scope, [
        'status' => LicenseStatus::Active,
        'activated_at' => now()->subDays(100),
        'expires_at' => now()->subDay(),
    ]);

    $this->artisan('licensing:check-expirations')->assertSuccessful();

    $audit = Audit::query()
        ->where('auditable_type', $license->getMorphClass())
        ->where('auditable_id', $license->getKey())
        ->where('event', 'updated')
        ->latest('id')
        ->firstOrFail();

    $this->assertSame(LicenseStatus::Active->value, $audit->old_values['status']);
    $this->assertSame(LicenseStatus::Expired->value, $audit->new_values['status']);
});

// -------------------------------------------------------------------------
// 執行記錄：每次跑都要留痕，用來事後追查排程是否真的啟動過
// -------------------------------------------------------------------------

/**
 * 零異動也必須留下記錄。少了這筆，「排程根本沒跑」與「排程跑了但沒東西到期」
 * 在事後完全無法區分——兩者都是零筆 audit。
 */
test('command logs a record on every run even when nothing expired', function (): void {
    Log::spy();

    $this->artisan('licensing:check-expirations')->assertSuccessful();

    // 總結行必須存在（證明有執行），且零異動時不該出現任何 ids 行。
    // withArgs 要排在 once 之前：Mockery 的 once() 算的是該方法的**所有**呼叫，
    // 先過濾才是「符合這個條件的呼叫恰好一次」。
    Log::shouldHaveReceived('info')->withArgs(
        fn (string $message, array $context): bool => $message === '[licensing] check-expirations'
            && $context['moved_to_expired'] === 0,
    )->once();

    Log::shouldNotHaveReceived('info', ['[licensing] check-expirations ids', Mockery::any()]);
});

test('command logs the ids of the licenses it expired', function () use ($makeLicense): void {
    $first = $makeLicense($this->scope, [
        'status' => LicenseStatus::Active,
        'activated_at' => now()->subDays(100),
        'expires_at' => now()->subDay(),
    ]);
    $second = $makeLicense($this->scope, [
        'status' => LicenseStatus::Pending,
        'activated_at' => null,
        'expires_at' => now()->subDays(2),
    ]);

    // 建完資料才開始側錄，避免 model 建立過程的 log 混進斷言。
    Log::spy();

    $this->artisan('licensing:check-expirations')->assertSuccessful();

    Log::shouldHaveReceived('info')->withArgs(
        fn (string $message, array $context): bool => $message === '[licensing] check-expirations'
            && $context['moved_to_expired'] === 2,
    )->once();

    Log::shouldHaveReceived('info')->withArgs(
        function (string $message, array $context) use ($first, $second): bool {
            // withArgs 會套到每一次 info 呼叫（含沒有 license_ids 的總結行），
            // 所以先短路，別直接取鍵。
            if ($message !== '[licensing] check-expirations ids') {
                return false;
            }

            $expected = [$first->getKey(), $second->getKey()];
            sort($expected);
            $actual = $context['license_ids'];
            sort($actual);

            return $context['batch'] === 1 && $actual === $expected;
        },
    )->once();
});

/**
 * 變更過的 id **一個都不能漏**。
 *
 * 逾量時的行為是換行繼續寫，不是截斷——所以這裡用超過單行寬度的筆數，
 * 斷言「把所有 ids 行併起來，剛好等於全部被變更的授權」。
 */
test('command logs every changed id without dropping any', function () use ($makeLicense): void {
    // 刻意超過 LOGGED_IDS_PER_LINE（200），逼出多行輸出。
    $expected = [];
    foreach (range(1, 205) as $ignored) {
        $expected[] = $makeLicense($this->scope, [
            'status' => LicenseStatus::Active,
            'activated_at' => now()->subDays(100),
            'expires_at' => now()->subDay(),
        ])->getKey();
    }

    $logged = [];
    Log::partialMock()
        ->shouldReceive('info')
        ->andReturnUsing(function (string $message, array $context) use (&$logged): void {
            if ($message === '[licensing] check-expirations ids') {
                $logged = array_merge($logged, $context['license_ids']);
            }
        });

    $this->artisan('licensing:check-expirations')->assertSuccessful();

    sort($expected);
    sort($logged);
    $this->assertSame($expected, $logged, '被變更的 license id 不得有任何一筆未進 log');
});

test('command output reports transition counts', function () use ($makeLicense): void {
    $makeLicense($this->scope, [
        'status' => LicenseStatus::Active,
        'activated_at' => now()->subDays(100),
        'expires_at' => now()->subDay(),
    ]);
    $makeLicense($this->scope, [
        'status' => LicenseStatus::Active,
        'activated_at' => now()->subDays(100),
        'expires_at' => now()->subDays(2),
    ]);

    $this->artisan('licensing:check-expirations')
        ->expectsOutputToContain('轉入 expired: 2')
        ->assertSuccessful();
});
