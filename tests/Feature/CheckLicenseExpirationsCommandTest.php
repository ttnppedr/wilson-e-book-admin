<?php

use App\Models\License;
use App\Models\LicenseScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
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
