<?php

/**
 * P-02：列表顯示 license uid、不逐列解密金鑰（表格有 60s poll，
 * 逐列解密會放大 CPU 成本）；完整金鑰僅在檢視頁解密顯示。
 */

use App\Filament\Resources\LicenseUsageResource\Pages\ListLicenseUsages;
use App\Filament\Resources\LicenseUsageResource\Pages\ViewLicenseUsage;
use App\Models\License;
use App\Models\LicenseScope;
use App\Models\LicenseUsage;
use App\Models\User;
use App\Services\LicenseKeyGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Enums\UsageStatus;

uses(RefreshDatabase::class);

$plainKey = 'ABCDE12345FGHIJ67890';

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

$makeUsage = function () use ($plainKey): LicenseUsage {
    $scope = LicenseScope::create([
        'name' => 'Test Scope',
        'is_active' => true,
        'default_max_usages' => 3,
        'default_duration_days' => 365,
    ]);

    $license = License::create([
        'key_hash' => hash('sha256', $plainKey),
        'status' => LicenseStatus::Active,
        'license_scope_id' => $scope->getKey(),
        'name' => 'Test License',
        'expires_at' => now()->addDays(365),
        'max_usages' => 3,
        'meta' => ['encrypted_key' => Crypt::encryptString($plainKey)],
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

test('list shows license uid without decrypted key', function () use ($makeUsage, $plainKey): void {
    $usage = $makeUsage();

    Livewire::test(ListLicenseUsages::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$usage])
        ->assertSee($usage->license->uid)
        ->assertDontSee(LicenseKeyGenerator::format($plainKey));
});

test('view page still shows formatted key', function () use ($makeUsage, $plainKey): void {
    $usage = $makeUsage();

    Livewire::test(ViewLicenseUsage::class, ['record' => $usage->getKey()])
        ->assertOk()
        ->assertSee(LicenseKeyGenerator::format($plainKey));
});
