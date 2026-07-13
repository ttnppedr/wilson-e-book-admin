<?php

namespace Tests\Feature\Filament;

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
use Tests\TestCase;

/**
 * P-02：列表顯示 license uid、不逐列解密金鑰（表格有 60s poll，
 * 逐列解密會放大 CPU 成本）；完整金鑰僅在檢視頁解密顯示。
 */
class LicenseUsageListTest extends TestCase
{
    use RefreshDatabase;

    private const PLAIN_KEY = 'ABCDE12345FGHIJ67890';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_list_shows_license_uid_without_decrypted_key(): void
    {
        $usage = $this->makeUsage();

        Livewire::test(ListLicenseUsages::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$usage])
            ->assertSee($usage->license->uid)
            ->assertDontSee(LicenseKeyGenerator::format(self::PLAIN_KEY));
    }

    public function test_view_page_still_shows_formatted_key(): void
    {
        $usage = $this->makeUsage();

        Livewire::test(ViewLicenseUsage::class, ['record' => $usage->getKey()])
            ->assertOk()
            ->assertSee(LicenseKeyGenerator::format(self::PLAIN_KEY));
    }

    private function makeUsage(): LicenseUsage
    {
        $scope = LicenseScope::create([
            'name' => 'Test Scope',
            'is_active' => true,
            'default_max_usages' => 3,
            'default_duration_days' => 365,
        ]);

        $license = License::create([
            'key_hash' => hash('sha256', self::PLAIN_KEY),
            'status' => LicenseStatus::Active,
            'license_scope_id' => $scope->getKey(),
            'name' => 'Test License',
            'expires_at' => now()->addDays(365),
            'max_usages' => 3,
            'meta' => ['encrypted_key' => Crypt::encryptString(self::PLAIN_KEY)],
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
    }
}
