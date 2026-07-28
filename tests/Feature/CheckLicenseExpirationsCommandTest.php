<?php

namespace Tests\Feature;

use App\Models\License;
use App\Models\LicenseScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Events\LicenseExpired;
use OwenIt\Auditing\Models\Audit;
use Tests\TestCase;

class CheckLicenseExpirationsCommandTest extends TestCase
{
    use RefreshDatabase;

    private LicenseScope $scope;

    protected function setUp(): void
    {
        parent::setUp();

        // 排程是系統自動執行（user_id 會是 null），但既有 audit 驗證
        // 預期有 actor，故仍建一位管理者登入保持測試 setup 一致。
        $this->actingAs(User::factory()->create());

        $this->scope = LicenseScope::create([
            'name' => 'Test Scope',
            'is_active' => true,
            'default_max_usages' => 3,
            'default_duration_days' => 365,
        ]);
    }

    public function test_active_license_past_expires_at_transitions_directly_to_expired(): void
    {
        Event::fake([LicenseExpired::class]);

        $license = $this->makeLicense([
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
    }

    public function test_license_never_enters_grace_status(): void
    {
        $license = $this->makeLicense([
            'status' => LicenseStatus::Active,
            'activated_at' => now()->subDays(100),
            'expires_at' => now()->subDay(),
        ]);

        // 直接呼叫也不得進入 Grace——覆寫在 model 層，vendor 自己的
        // CheckExpirationsCommand 呼叫這支時同樣走不進去。
        $license->transitionToGrace();

        $this->assertSame(LicenseStatus::Active, $license->refresh()->status);
    }

    public function test_legacy_grace_license_is_drained_to_expired(): void
    {
        Event::fake([LicenseExpired::class]);

        // 舊流程殘留的 grace 資料：不論當初的寬限窗多長，一律收斂為 expired。
        $license = $this->makeLicense([
            'status' => LicenseStatus::Grace,
            'activated_at' => now()->subDays(200),
            'expires_at' => now()->subDay(),
        ]);

        $this->artisan('licensing:check-expirations')->assertSuccessful();

        $this->assertSame(LicenseStatus::Expired, $license->refresh()->status);
        Event::assertDispatched(LicenseExpired::class);
    }

    public function test_active_license_not_yet_expired_is_untouched(): void
    {
        $license = $this->makeLicense([
            'status' => LicenseStatus::Active,
            'activated_at' => now()->subDay(),
            'expires_at' => now()->addDays(30),
        ]);

        $this->artisan('licensing:check-expirations')->assertSuccessful();

        $this->assertSame(LicenseStatus::Active, $license->refresh()->status);
    }


    public function test_suspended_license_with_past_expires_at_is_untouched(): void
    {
        $license = $this->makeLicense([
            'status' => LicenseStatus::Suspended,
            'activated_at' => now()->subDays(100),
            'expires_at' => now()->subDay(),
        ]);

        $this->artisan('licensing:check-expirations')->assertSuccessful();

        $this->assertSame(LicenseStatus::Suspended, $license->refresh()->status);
    }

    public function test_cancelled_license_with_past_expires_at_is_untouched(): void
    {
        $license = $this->makeLicense([
            'status' => LicenseStatus::Cancelled,
            'activated_at' => now()->subDays(100),
            'expires_at' => now()->subDay(),
        ]);

        $this->artisan('licensing:check-expirations')->assertSuccessful();

        $this->assertSame(LicenseStatus::Cancelled, $license->refresh()->status);
    }

    public function test_license_without_expires_at_is_untouched(): void
    {
        $license = $this->makeLicense([
            'status' => LicenseStatus::Active,
            'activated_at' => now()->subDays(100),
            'expires_at' => null,
        ]);

        $this->artisan('licensing:check-expirations')->assertSuccessful();

        $this->assertSame(LicenseStatus::Active, $license->refresh()->status);
    }

    public function test_transition_writes_audit_record(): void
    {
        $license = $this->makeLicense([
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
    }

    public function test_command_output_reports_transition_counts(): void
    {
        $this->makeLicense([
            'status' => LicenseStatus::Active,
            'activated_at' => now()->subDays(100),
            'expires_at' => now()->subDay(),
        ]);
        $this->makeLicense([
            'status' => LicenseStatus::Active,
            'activated_at' => now()->subDays(100),
            'expires_at' => now()->subDays(2),
        ]);

        $this->artisan('licensing:check-expirations')
            ->expectsOutputToContain('轉入 expired: 2')
            ->assertSuccessful();
    }

    private function makeLicense(array $attributes): License
    {
        return License::create(array_merge([
            'key_hash' => hash('sha256', fake()->uuid()),
            'license_scope_id' => $this->scope->getKey(),
            'name' => 'Test License',
            'max_usages' => 3,
            'meta' => [],
        ], $attributes));
    }
}
