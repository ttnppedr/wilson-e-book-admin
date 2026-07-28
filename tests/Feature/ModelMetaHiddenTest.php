<?php

/**
 * F-02 / F-03：meta 欄位不得隨序列化（Filament/Livewire dehydrate 走同一條路）
 * 下發到前端。LicenseUsage.meta 內含 validate API 回寫的 client_data。
 */

use App\Models\License;
use App\Models\LicenseScope;
use App\Models\LicenseUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Enums\UsageStatus;

uses(RefreshDatabase::class);

$makeScope = function (): LicenseScope {
    return LicenseScope::create([
        'name' => 'Test Scope',
        'is_active' => true,
        'default_max_usages' => 3,
        'default_duration_days' => 365,
        'meta' => ['note' => 'internal'],
    ]);
};

$makeUsage = function () use ($makeScope): LicenseUsage {
    $license = License::create([
        'key_hash' => hash('sha256', fake()->uuid()),
        'status' => LicenseStatus::Active,
        'license_scope_id' => $makeScope()->getKey(),
        'name' => 'Test License',
        'expires_at' => now()->addDays(365),
        'max_usages' => 3,
        'meta' => [],
    ]);

    return LicenseUsage::create([
        'license_id' => $license->getKey(),
        'usage_fingerprint' => fake()->uuid(),
        'status' => UsageStatus::Active,
        'registered_at' => now(),
        'last_seen_at' => now(),
        'client_type' => 'desktop',
        'meta' => [],
    ]);
};

test('license usage meta is hidden from serialization', function () use ($makeUsage): void {
    $usage = $makeUsage();
    $usage->meta = ['client_data' => ['os' => 'macOS']];
    $usage->save();

    $this->assertArrayNotHasKey('meta', $usage->fresh()->toArray());
});

test('license scope meta is hidden from serialization', function () use ($makeScope): void {
    $scope = $makeScope();

    $this->assertArrayNotHasKey('meta', $scope->fresh()->toArray());
});

test('license usage meta remains readable as attribute', function () use ($makeUsage): void {
    $usage = $makeUsage();
    $usage->meta = ['client_data' => ['os' => 'macOS']];
    $usage->save();

    $this->assertSame(['os' => 'macOS'], $usage->fresh()->meta['client_data']);
});
