<?php

use App\Filament\Resources\LicenseScopeResource\Pages\ListLicenseScopes;
use App\Models\License;
use App\Models\LicenseScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use LucaLongo\Licensing\Enums\LicenseStatus;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

$createLicense = function (LicenseScope $scope, LicenseStatus $status): License {
    return License::create([
        'key_hash' => hash('sha256', fake()->uuid()),
        'status' => $status,
        'license_scope_id' => $scope->getKey(),
        'name' => 'Test License',
        'activated_at' => $status === LicenseStatus::Active ? now() : null,
        'expires_at' => now()->addDays(365),
        'max_usages' => 3,
        'meta' => [],
    ]);
};

test('active licenses count only counts active licenses', function () use ($createLicense): void {
    $scope = LicenseScope::create([
        'name' => 'Test Scope',
        'is_active' => true,
        'default_max_usages' => 3,
        'default_duration_days' => 365,
    ]);

    $createLicense($scope, LicenseStatus::Active);
    $createLicense($scope, LicenseStatus::Active);
    $createLicense($scope, LicenseStatus::Pending);

    Livewire::test(ListLicenseScopes::class)
        ->assertCanSeeTableRecords([$scope])
        ->assertTableColumnStateSet('licenses_count', 3, record: $scope)
        ->assertTableColumnStateSet('active_licenses_count', 2, record: $scope);
});
