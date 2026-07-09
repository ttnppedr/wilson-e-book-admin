<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\LicenseScopeResource\Pages\ListLicenseScopes;
use App\Models\License;
use App\Models\LicenseScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use LucaLongo\Licensing\Enums\LicenseStatus;
use Tests\TestCase;

class LicenseScopeListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    private function createLicense(LicenseScope $scope, LicenseStatus $status): License
    {
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
    }

    public function test_active_licenses_count_only_counts_active_licenses(): void
    {
        $scope = LicenseScope::create([
            'name' => 'Test Scope',
            'is_active' => true,
            'default_max_usages' => 3,
            'default_duration_days' => 365,
        ]);

        $this->createLicense($scope, LicenseStatus::Active);
        $this->createLicense($scope, LicenseStatus::Active);
        $this->createLicense($scope, LicenseStatus::Pending);

        Livewire::test(ListLicenseScopes::class)
            ->assertCanSeeTableRecords([$scope])
            ->assertTableColumnStateSet('licenses_count', 3, record: $scope)
            ->assertTableColumnStateSet('active_licenses_count', 2, record: $scope);
    }
}
