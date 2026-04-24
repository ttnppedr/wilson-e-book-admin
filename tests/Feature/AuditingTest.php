<?php

namespace Tests\Feature;

use App\Models\License;
use App\Models\LicenseScope;
use App\Models\LicenseUsage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Enums\UsageStatus;
use OwenIt\Auditing\Models\Audit;
use Tests\TestCase;

class AuditingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->actingAs($this->admin);
    }

    public function test_creating_license_scope_writes_audit_record_with_actor(): void
    {
        $scope = LicenseScope::create([
            'name' => 'Scope A',
            'is_active' => true,
            'default_max_usages' => 3,
            'default_duration_days' => 365,
        ]);

        $audit = Audit::query()
            ->where('auditable_type', $scope->getMorphClass())
            ->where('auditable_id', $scope->getKey())
            ->where('event', 'created')
            ->firstOrFail();

        $this->assertSame((string) $this->admin->getKey(), (string) $audit->user_id);
        $this->assertSame($this->admin::class, $audit->user_type);
        $this->assertSame('Scope A', $audit->new_values['name']);
    }

    public function test_updating_license_scope_writes_audit_with_diff(): void
    {
        $scope = LicenseScope::create([
            'name' => 'Original',
            'is_active' => true,
            'default_max_usages' => 1,
            'default_duration_days' => 30,
        ]);

        $scope->update(['name' => 'Renamed']);

        $audit = Audit::query()
            ->where('auditable_type', $scope->getMorphClass())
            ->where('auditable_id', $scope->getKey())
            ->where('event', 'updated')
            ->firstOrFail();

        $this->assertSame('Original', $audit->old_values['name']);
        $this->assertSame('Renamed', $audit->new_values['name']);
    }

    public function test_deleting_license_scope_writes_audit_record(): void
    {
        $scope = LicenseScope::create([
            'name' => 'Throwaway',
            'is_active' => true,
            'default_max_usages' => 1,
            'default_duration_days' => 30,
        ]);
        $scopeId = $scope->getKey();

        $scope->delete();

        $this->assertDatabaseHas('audits', [
            'auditable_type' => (new LicenseScope)->getMorphClass(),
            'auditable_id' => $scopeId,
            'event' => 'deleted',
            'user_id' => $this->admin->getKey(),
        ]);
    }

    public function test_creating_license_writes_audit_record(): void
    {
        $scope = $this->makeScope();
        $license = $this->makeLicense($scope);

        $this->assertDatabaseHas('audits', [
            'auditable_type' => $license->getMorphClass(),
            'auditable_id' => $license->getKey(),
            'event' => 'created',
            'user_id' => $this->admin->getKey(),
        ]);
    }

    public function test_updating_license_writes_audit_with_diff(): void
    {
        $license = $this->makeLicense($this->makeScope());

        $license->update(['max_usages' => 99]);

        $audit = Audit::query()
            ->where('auditable_type', $license->getMorphClass())
            ->where('auditable_id', $license->getKey())
            ->where('event', 'updated')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(3, $audit->old_values['max_usages']);
        $this->assertSame(99, $audit->new_values['max_usages']);
    }

    public function test_deleting_license_writes_audit_record(): void
    {
        $license = $this->makeLicense($this->makeScope());
        $licenseId = $license->getKey();
        $licenseType = $license->getMorphClass();

        $license->delete();

        $this->assertDatabaseHas('audits', [
            'auditable_type' => $licenseType,
            'auditable_id' => $licenseId,
            'event' => 'deleted',
            'user_id' => $this->admin->getKey(),
        ]);
    }

    public function test_license_usage_heartbeat_does_not_write_audit(): void
    {
        $usage = $this->makeUsage($this->makeLicense($this->makeScope()));

        $auditCountBefore = Audit::query()
            ->where('auditable_type', $usage->getMorphClass())
            ->where('auditable_id', $usage->getKey())
            ->count();

        $usage->heartbeat();

        $auditCountAfter = Audit::query()
            ->where('auditable_type', $usage->getMorphClass())
            ->where('auditable_id', $usage->getKey())
            ->count();

        $this->assertSame(
            $auditCountBefore,
            $auditCountAfter,
            'Heartbeat updates 只改 last_seen_at，應被 $auditExclude + 空值守門員攔截。',
        );
    }

    public function test_license_usage_non_heartbeat_update_writes_audit(): void
    {
        $usage = $this->makeUsage($this->makeLicense($this->makeScope()));

        $usage->update([
            'status' => UsageStatus::Revoked,
            'revoked_at' => now(),
        ]);

        $audit = Audit::query()
            ->where('auditable_type', $usage->getMorphClass())
            ->where('auditable_id', $usage->getKey())
            ->where('event', 'updated')
            ->firstOrFail();

        $this->assertArrayHasKey('status', $audit->new_values);
        $this->assertSame(UsageStatus::Revoked->value, $audit->new_values['status']);
    }

    public function test_vendor_licensing_audit_log_table_is_not_written(): void
    {
        $license = $this->makeLicense($this->makeScope());
        $license->update(['max_usages' => 50]);

        $this->assertSame(
            0,
            \DB::table('licensing_audit_logs')->count(),
            'Vendor 內建 audit 已關閉，licensing_audit_logs 應保持為空。',
        );
    }

    private function makeScope(): LicenseScope
    {
        return LicenseScope::create([
            'name' => 'Test Scope',
            'is_active' => true,
            'default_max_usages' => 3,
            'default_duration_days' => 365,
        ]);
    }

    private function makeLicense(LicenseScope $scope): License
    {
        return License::create([
            'key_hash' => hash('sha256', fake()->uuid()),
            'status' => LicenseStatus::Pending,
            'license_scope_id' => $scope->getKey(),
            'name' => 'Test License',
            'expires_at' => now()->addDays(365),
            'max_usages' => 3,
            'meta' => [],
        ]);
    }

    private function makeUsage(License $license): LicenseUsage
    {
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
